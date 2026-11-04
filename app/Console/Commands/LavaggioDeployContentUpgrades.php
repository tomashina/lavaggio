<?php

namespace App\Console\Commands;

use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Import\LavaggioPresentationSyncService;
use App\Services\Settings\SystemSettingsService;
use App\Support\LavaggioDeployMigrations;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LavaggioDeployContentUpgrades extends Command
{
    protected $signature = 'lavaggio:deploy-content-upgrades {--apply : Apply only explicitly reviewed content migrations} {--backup-dir= : Existing private deployment backup directory}';

    protected $description = 'Preview or apply the reviewed Lavaggio content upgrade after a private snapshot';

    public function handle(Migrator $migrator): int
    {
        try {
            $this->guardDestination();
            foreach (LavaggioDeployMigrations::FILES as $path) {
                if (! is_file(base_path($path)) || is_link(base_path($path)) || realpath(base_path($path)) !== base_path($path)) {
                    throw new RuntimeException('The reviewed migration must be an existing regular project file.');
                }
            }
            $repository = $migrator->getRepository();
            if (! $repository->repositoryExists()) {
                throw new RuntimeException('The existing migration ledger is required.');
            }
            $files = $migrator->getMigrationFiles(database_path('migrations'));
            $pending = array_diff(array_keys($files), $repository->getRan());
            $allowed = array_map(fn ($path) => basename($path, '.php'), LavaggioDeployMigrations::FILES);
            if (array_diff($pending, $allowed) !== []) {
                throw new RuntimeException('Unreviewed pending migrations require separate review; no upgrade was applied.');
            }
            if ($pending === []) {
                if ($this->option('apply')) {
                    $this->invalidateCaches();
                }
                $this->info('Reviewed content migrations are already recorded; existing editorial decisions are preserved.');

                return self::SUCCESS;
            }
            $this->ensureHomepageExists();
            if (in_array('2026_11_04_120100_sync_lavaggio_approved_presentation', $pending, true)) {
                app(LavaggioPresentationSyncService::class)->plan();
            }
            $this->info('Pending reviewed content migration: '.implode(', ', $pending));
            if (! $this->option('apply')) {
                $this->info('Preview only. No backup or database writes were performed.');

                return self::SUCCESS;
            }

            $backup = $this->backup();
            $this->info('Private content snapshot saved: '.$backup);
            $paths = array_values(array_filter(LavaggioDeployMigrations::FILES, fn ($path) => in_array(basename($path, '.php'), $pending, true)));
            if ($this->call('migrate', ['--path' => $paths, '--force' => true]) !== self::SUCCESS) {
                throw new RuntimeException('The reviewed migration failed. Inspect the private snapshot before retrying.');
            }
            if (array_diff($pending, $repository->getRan()) !== []) {
                throw new RuntimeException('The reviewed migration was not recorded.');
            }
            if (in_array('2026_11_04_113000_add_lavaggio_home_problems_widget', $pending, true) && ! $this->problemWidgetExists()) {
                throw new RuntimeException('The problem widget was not found after the content upgrade.');
            }
            if (in_array('2026_11_04_120100_sync_lavaggio_approved_presentation', $pending, true)) {
                $remaining = app(LavaggioPresentationSyncService::class)->plan();
                if ($remaining['settings'] !== [] || $remaining['blocks'] !== []) {
                    throw new RuntimeException('The approved presentation still differs after the upgrade. Inspect the private snapshot.');
                }
            }
            $this->invalidateCaches();
            $this->info('Reviewed Lavaggio content upgrade completed and storefront caches invalidated.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception::class === RuntimeException::class ? $exception->getMessage() : 'Content upgrade failed; inspect the private deployment log and backup.');

            return self::FAILURE;
        }
    }

    private function guardDestination(): void
    {
        if (config('storefront.home') !== 'lavaggio' || ! app()->environment(['local', 'testing', 'staging'])) {
            throw new RuntimeException('Content upgrades require the Lavaggio storefront.');
        }
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if ($driver === 'mysql') {
            if ($connection->getConfig('host') !== 'localhost' || (string) $connection->getConfig('port') !== '3306'
                || $connection->getDatabaseName() !== 'baredahr_lavaggioshop'
                || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'baredahr_lavaggioshop') {
                throw new RuntimeException('Only the dedicated Lavaggio shop database is allowed.');
            }

            return;
        }
        if ($driver === 'sqlite' && app()->environment('testing') && $connection->getDatabaseName() === ':memory:') {
            return;
        }
        $expected = realpath(database_path('database.sqlite'));
        if ($driver !== 'sqlite' || ! $expected || is_link(database_path()) || is_link(database_path('database.sqlite'))
            || $expected !== realpath(base_path()).'/database/database.sqlite' || $connection->getDatabaseName() !== $expected) {
            throw new RuntimeException('Only the dedicated shop database or this project SQLite database is allowed.');
        }
        $active = collect($connection->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            throw new RuntimeException('SQLite is connected to an unexpected database.');
        }
    }

    private function problemWidgetExists(): bool
    {
        return DB::table('content_blocks')->where('code', 'lavaggio-home-problems')->exists()
            || DB::table('content_blocks')->where('type', 'lavaggio_problems')
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('content_block_slots')
                    ->whereColumn('content_block_slots.content_block_id', 'content_blocks.id')->where('placement', 'home.content'))->exists();
    }

    private function ensureHomepageExists(): void
    {
        if ($this->problemWidgetExists()) {
            return;
        }
        if (! DB::table('content_blocks')->where('type', 'lavaggio_campaigns')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('content_block_slots')
                ->whereColumn('content_block_slots.content_block_id', 'content_blocks.id')->where('placement', 'home.content')
                ->whereNull('target_type')
                ->where(fn ($variant) => $variant->whereNull('frontend_variant')->orWhere('frontend_variant', 'all')))->exists()) {
            throw new RuntimeException('The existing Lavaggio homepage promo block is required before this upgrade.');
        }
    }

    private function backup(): string
    {
        $directory = rtrim((string) $this->option('backup-dir'), '/');
        $publicDirectories = array_filter([realpath(public_path()), realpath(storage_path('app/public'))]);
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : fileowner(base_path('artisan'));
        if ($directory === '' || realpath($directory) !== $directory || is_link($directory) || ! is_dir($directory)
            || ! is_writable($directory) || (fileperms($directory) & 0077) !== 0 || fileowner($directory) !== $owner
            || collect($publicDirectories)->contains(fn ($public) => $directory === $public || str_starts_with($directory, $public.'/'))) {
            throw new RuntimeException('Provide an existing canonical private backup directory (0700).');
        }
        $blocks = DB::table('content_blocks')->orderBy('id')->get();
        $ids = $blocks->pluck('id')->all();
        $snapshot = [
            'upgrade' => LavaggioDeployMigrations::FILES,
            'captured_at' => now()->toIso8601String(),
            'database' => DB::connection()->getDatabaseName(),
            'content_blocks' => $blocks,
            'content_block_translations' => DB::table('content_block_translations')->whereIn('content_block_id', $ids)->get(),
            'content_block_slots' => DB::table('content_block_slots')->orderBy('id')->get(),
            'system_settings' => DB::table('system_settings')->where(function ($query): void {
                foreach (['front_navigation_', 'lavaggio_', 'store_footer_', 'store_announcement_', 'store_social_'] as $prefix) {
                    $query->orWhere('key', 'like', $prefix.'%');
                }
            })->orderBy('id')->get(),
            'migrations' => DB::table('migrations')->orderBy('id')->get(),
        ];
        foreach (['content_block_items', 'content_info_pages', 'content_info_page_translations', 'content_faqs', 'content_faq_translations', 'content_blog_post_translations'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get();
        }
        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $path = $directory.'/before-content-upgrade-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';
        $previousMask = umask(0077);
        try {
            $file = fopen($path, 'xb');
        } finally {
            umask($previousMask);
        }
        if ($file === false) {
            throw new RuntimeException('The private content snapshot could not be created.');
        }
        try {
            if (fwrite($file, $json) !== strlen($json) || ! fflush($file) || ! chmod($path, 0600)) {
                throw new RuntimeException('The private content snapshot could not be completed; no migration was run.');
            }
        } finally {
            fclose($file);
        }

        return $path;
    }

    private function invalidateCaches(): void
    {
        app(SystemSettingsService::class)->flush();
        ContentBlockResolver::bumpCacheVersion();
        app(GuestStorefrontCache::class)->invalidate();
    }
}
