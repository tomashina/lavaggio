<?php

namespace App\Console\Commands;

use Database\Seeders\LavaggioDemoContentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class LavaggioSeedDemoContent extends Command
{
    protected $signature = 'lavaggio:seed-demo-content';

    protected $description = 'Back up the local Lavaggio database, then add demo product reviews and promotions';

    public function handle(): int
    {
        $expected = realpath(database_path('database.sqlite'));
        if (! app()->environment('local') || config('storefront.home') !== 'lavaggio'
            || config('database.default') !== 'sqlite' || ! $expected
            || config('database.connections.sqlite.database') !== $expected
            || is_link(database_path('database.sqlite'))) {
            $this->error('Restricted to the local Lavaggio database.');

            return self::FAILURE;
        }
        $active = collect(DB::connection()->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            $this->error('The active database is not the verified local destination.');

            return self::FAILURE;
        }

        $backupDirectory = storage_path('app/private/lavaggio-demo-backups');
        File::ensureDirectoryExists($backupDirectory);
        $backup = $backupDirectory.'/before-demo-content-'.now()->format('Ymd-His-u').'.sqlite';
        // SQLite produces a consistent snapshot, including any committed WAL data.
        DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($backup));
        $this->info('Database backup: '.$backup);
        $seeder = app(LavaggioDemoContentSeeder::class);
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }
}
