<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;

class LavaggioPrepareStore extends Command
{
    protected $signature = 'lavaggio:prepare-store {--execute : Back up and empty this local Lavaggio store}';

    protected $description = 'Empty the isolated local Lavaggio shop while preserving Tomislav’s existing administrator password';

    private const ADMIN_EMAIL = 'tomislav@agmedia.hr';

    // Keep the application’s schema, access rules, localization and order-state infrastructure.
    // Everything else is store data and is emptied, including future imported data tables.
    private const RETAINED_TABLES = [
        'migrations', 'users', 'roles', 'abilities', 'assigned_roles', 'permissions',
        'system_settings', 'languages', 'currencies', 'tax_rates', 'regions',
        'geo_zones', 'geo_zone_countries', 'order_statuses',
    ];

    private const DATA_DIRECTORIES = [
        'storage/app/private', 'storage/app/public', 'public/image',
        'storage/media-library', 'storage/framework/sessions',
        'storage/framework/cache/data', 'storage/logs',
    ];

    public function handle(): int
    {
        try {
            $memoryTest = $this->guardDestination();
            $db = DB::connection('sqlite');
            $admin = $db->table('users')
                ->whereRaw('LOWER(email) = ?', [self::ADMIN_EMAIL])
                ->where('account_type', 'staff')
                ->orderByDesc('email_verified_at')
                ->first();

            if (! $admin || ! is_string($admin->password) || $admin->password === '') {
                throw new RuntimeException('The existing Tomislav staff account is required; no password has been created or changed.');
            }
            if ($db->table('user_legacy_credentials')->where('user_id', $admin->id)
                ->where('enabled', true)->whereNull('retired_at')->exists()) {
                throw new RuntimeException('The administrator still depends on a legacy password; complete its migration before cleanup.');
            }

            $tables = array_map(fn ($row) => $row->name, $db->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
            $purgeTables = array_values(array_diff($tables, self::RETAINED_TABLES));
            $before = $this->counts($db, $tables);
            $this->table(['Store data', 'Before'], [
                ['Products', $before['products'] ?? 0],
                ['Categories', $before['categories'] ?? 0],
                ['Brands', $before['catalog_manufacturers'] ?? 0],
                ['Orders', $before['orders'] ?? 0],
                ['Customer accounts', $db->table('users')->where('account_type', 'customer')->count()],
            ]);

            if (! $this->option('execute')) {
                $this->info('Preview only. Use --execute to back up and empty the local store.');

                return self::SUCCESS;
            }

            $backup = $memoryTest ? null : $this->backup($db, $admin, $before);

            $db->transaction(function () use ($db, $admin, $purgeTables): void {
                // Defer checks until every related business table has been emptied.
                // Foreign keys remain enabled, and the result is checked before commit.
                $db->statement('PRAGMA defer_foreign_keys = ON');
                foreach ($purgeTables as $table) {
                    $db->table($table)->delete();
                }

                $db->table('assigned_roles')->delete();
                $db->table('permissions')->where('entity_type', (new User)->getMorphClass())->delete();
                $db->table('users')->where('id', '!=', $admin->id)->delete();
                $db->table('users')->where('id', $admin->id)->update([
                    'account_type' => 'staff',
                    'admin_login_enabled' => true,
                    'api_access_enabled' => false,
                    'remember_token' => null,
                ]);

                $user = User::findOrFail($admin->id);
                Bouncer::role()->firstOrCreate(['name' => 'superadmin'], ['title' => 'Super Administrator']);
                Bouncer::role()->firstOrCreate(['name' => 'admin'], ['title' => 'Administrator']);
                Bouncer::assign(['superadmin', 'admin'])->to($user);
                Bouncer::allow('superadmin')->everything();
                Bouncer::refresh();

                // Empty store tables start at 1 again. The surviving staff identity stays intact.
                $db->table('sqlite_sequence')->whereIn('name', $purgeTables)->delete();
                $db->table('sqlite_sequence')->where('name', 'users')->update(['seq' => $admin->id]);

                foreach ($purgeTables as $table) {
                    if ($db->table($table)->exists()) {
                        throw new RuntimeException('Store data verification failed; the database changes were rolled back.');
                    }
                }
                if ($db->table('users')->count() !== 1 || ! hash_equals($admin->password, $user->getAuthPassword())
                    || ! $user->isA('superadmin') || ! $user->can('admin.access')
                    || $db->select('PRAGMA foreign_key_check') !== []) {
                    throw new RuntimeException('Administrator or foreign key verification failed; the database changes were rolled back.');
                }
            });

            if (! $memoryTest) {
                // Remove deleted private rows from SQLite's free pages as well.
                $db->statement('VACUUM');
                foreach (self::DATA_DIRECTORIES as $relative) {
                    $this->clearDirectory(base_path($relative));
                }
                $this->callSilent('view:clear');
                $this->privateWrite($backup.'/report.json', json_encode([
                    'before' => $before,
                    'after' => $this->counts($db, $tables),
                    'password_preserved' => true,
                    'foreign_key_violations' => 0,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $this->info('Private backup: '.$backup);
            }

            $this->info('Store data is empty. One Tomislav staff account remains with full administrator access and its original password.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Database exception bindings can contain private records. Never print them.
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Cleanup stopped: '.get_class($exception));

            return self::FAILURE;
        }
    }

    private function guardDestination(): bool
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.driver') !== 'sqlite'
            || config('database.connections.sqlite.url')) {
            throw new RuntimeException('Cleanup is restricted to the local Lavaggio SQLite connection.');
        }

        $configured = config('database.connections.sqlite.database');
        if (app()->environment('testing') && $configured === ':memory:') {
            return true;
        }

        $root = realpath(base_path());
        $expected = realpath(base_path('database/database.sqlite'));
        if (! app()->environment('local') || ! $root || basename($root) !== 'lavaggioshop'
            || ! is_string($configured) || ! str_starts_with($configured, DIRECTORY_SEPARATOR)
            || ! $expected || $configured !== $expected || $expected !== $root.'/database/database.sqlite'
            || is_link(base_path('database/database.sqlite')) || is_link(base_path('database'))) {
            throw new RuntimeException('Cleanup refused: use only the local lavaggioshop/database/database.sqlite destination.');
        }
        $active = collect(DB::connection('sqlite')->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            throw new RuntimeException('Cleanup refused: the active database is not the verified local destination.');
        }

        return false;
    }

    private function counts(Connection $db, array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $db->table($table)->count();
        }

        return $counts;
    }

    private function backup(Connection $db, object $admin, array $before): string
    {
        $directory = base_path('.local/backups/lavaggio-prepare-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)));
        if (! File::makeDirectory($directory, 0700, true)) {
            throw new RuntimeException('The private backup directory could not be created.');
        }
        $db->statement('VACUUM INTO '.$db->getPdo()->quote($directory.'/database.sqlite'));
        chmod($directory.'/database.sqlite', 0600);
        foreach (['.env', '.local/admin-access.txt'] as $relative) {
            if (is_file(base_path($relative))) {
                $this->privateWrite($directory.'/'.basename($relative), File::get(base_path($relative)));
            }
        }
        $this->privateWrite($directory.'/tomislav-admin.json', json_encode((array) $admin, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $this->privateWrite($directory.'/before-counts.json', json_encode($before, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        foreach (self::DATA_DIRECTORIES as $relative) {
            $source = base_path($relative);
            if (! is_dir($source)) {
                continue;
            }
            if (is_link($source) || ! str_starts_with((string) realpath($source), realpath(base_path()).DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('An imported data directory points outside this local project.');
            }
            $target = $directory.'/files/'.$relative;
            File::ensureDirectoryExists(dirname($target), 0700, true);
            if (! File::copyDirectory($source, $target)) {
                throw new RuntimeException('Imported files could not be backed up; cleanup stopped.');
            }
        }
        foreach (File::allFiles($directory, true) as $file) {
            chmod($file->getPathname(), 0600);
        }
        foreach (File::directories($directory) as $child) {
            $this->privateDirectory($child);
        }

        return $directory;
    }

    private function privateWrite(string $path, string $contents): void
    {
        if (File::put($path, $contents) === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Private backup could not be written securely.');
        }
    }

    private function privateDirectory(string $directory): void
    {
        chmod($directory, 0700);
        foreach (File::directories($directory) as $child) {
            $this->privateDirectory($child);
        }
    }

    private function clearDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (File::files($directory, true) as $file) {
            if ($file->getFilename() !== '.gitignore' && ! File::delete($file->getPathname())) {
                throw new RuntimeException('The database is empty, but an imported file could not be removed. The private backup is available.');
            }
        }
        foreach (File::directories($directory) as $child) {
            if (! File::deleteDirectory($child)) {
                throw new RuntimeException('The database is empty, but an imported directory could not be removed. The private backup is available.');
            }
        }
    }
}
