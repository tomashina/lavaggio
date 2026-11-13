<?php

namespace App\Console\Commands;

use App\Models\Catalog\Product\Product;
use App\Services\Import\LavaggioCatalogReplacement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

class LavaggioReplaceCatalog extends Command
{
    protected $signature = 'lavaggio:replace-catalog {snapshot : Prepared catalog.json path} {--execute : Replace all existing products after creating a private database backup} {--expected-products=48 : Expected number of source products} {--expected-existing=85 : Expected existing product count}';

    protected $description = 'Replace the Lavaggio catalogue with the approved spreadsheet and SKU-matched local images';

    public function handle(LavaggioCatalogReplacement $importer): int
    {
        $lock = null;
        try {
            $this->guardDestination();
            $snapshot = $importer->read($this->argument('snapshot'));
            if (count($snapshot['products']) !== (int) $this->option('expected-products')) {
                throw new RuntimeException('Unexpected source product count.');
            }
            if ($importer->alreadyApplied($snapshot)) {
                $this->info('This exact catalogue is already imported. No prices, stock or images were changed.');

                return self::SUCCESS;
            }
            if (Product::query()->count() !== (int) $this->option('expected-existing')) {
                throw new RuntimeException('Existing catalogue count changed. Review before replacement.');
            }
            $this->info(json_encode(['existing_products' => Product::query()->count(), 'source_products' => count($snapshot['products']),
                'brands' => array_values(array_unique(array_column($snapshot['products'], 'brand'))),
                'stock' => array_sum(array_column($snapshot['products'], 'stock')), 'sha256' => $snapshot['sha256']]));
            if (! $this->option('execute')) {
                $this->info('Preflight passed. No database changes.');

                return self::SUCCESS;
            }
            if (DB::getDriverName() === 'mysql' && ! app()->isDownForMaintenance()) {
                throw new RuntimeException('Put the hosted shop in maintenance mode before replacement.');
            }
            $lock = fopen(storage_path('app/private/catalog-replacement.lock'), 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another catalogue replacement is in progress.');
            }
            $backup = $this->backupDatabase();
            $this->info('Private database backup: '.$backup);
            $result = $importer->replace($snapshot);
            $this->info(json_encode($result));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function guardDestination(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            throw new RuntimeException('Not a Lavaggio storefront.');
        }
        if (app()->environment('testing') && DB::getDatabaseName() === ':memory:') {
            return;
        }
        if (app()->environment('local') && DB::getDriverName() === 'sqlite'
            && realpath(DB::getDatabaseName()) === realpath(database_path('database.sqlite'))
            && ! is_link(database_path('database.sqlite'))) {
            return;
        }
        if (realpath(base_path()) === '/home/baredahr/shop.lavaggio.hr' && DB::getDriverName() === 'mysql'
            && DB::getDatabaseName() === 'baredahr_lavaggioshop'
            && DB::selectOne('SELECT DATABASE() AS name')->name === 'baredahr_lavaggioshop') {
            return;
        }
        throw new RuntimeException('Destination is not the verified local or hosted Lavaggio shop database.');
    }

    private function backupDatabase(): string
    {
        $directory = storage_path('app/private/backups/catalog-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)));
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot create private backup directory.');
        }
        if (DB::getDriverName() === 'sqlite') {
            $path = $directory.'/database.sqlite';
            DB::statement('VACUUM INTO '.DB::getPdo()->quote($path));
            chmod($path, 0600);

            return $path;
        }
        $connection = DB::connection()->getConfig();
        $credentials = $directory.'/mysql-client.cnf';
        $quote = fn ($value) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $value).'"';
        $handle = fopen($credentials, 'x');
        chmod($credentials, 0600);
        fwrite($handle, "[client]\n".'host='.$quote($connection['host'])."\n".'user='.$quote($connection['username'])."\n".'password='.$quote($connection['password'])."\n".'port='.(int) ($connection['port'] ?? 3306)."\n");
        fclose($handle);
        $path = $directory.'/database.sql';
        try {
            $process = new Process(['mysqldump', '--defaults-extra-file='.$credentials, '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', '--hex-blob', '--result-file='.$path, $connection['database']]);
            $process->setTimeout(120);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($path) || filesize($path) < 1000) {
                throw new RuntimeException('Database backup failed; catalogue remains unchanged.');
            }
            chmod($path, 0600);

            return $path;
        } finally {
            @unlink($credentials);
        }
    }
}
