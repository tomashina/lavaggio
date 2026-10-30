<?php

namespace App\Console\Commands;

use App\Services\Import\LavaggioProductImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LavaggioImportProducts extends Command
{
    protected $signature = 'lavaggio:import-products {snapshot? : Product JSON snapshot} {--execute : Import into the local Lavaggio store} {--demo-promotions : Add local example promotions to every fifth snapshot product}';

    protected $description = 'Import researched national-brand products, local photographs and editable laundry filters';

    public function handle(LavaggioProductImportService $importer): int
    {
        try {
            $this->guardDestination();
            $path = $this->argument('snapshot') ?: database_path('data/lavaggio-products.json');
            if (! is_readable($path)) {
                throw new RuntimeException('Product snapshot is not readable.');
            }
            $items = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($items) || ! array_is_list($items)) {
                throw new RuntimeException('Expected a product JSON array.');
            }
            $this->info(count($items).' researched products in snapshot.');
            if (! $this->option('execute')) {
                $this->info('Preview only. Add --execute to import.');

                return self::SUCCESS;
            }
            config(['media-library.queue_conversions_by_default' => false]);
            $result = $importer->import($items, (bool) $this->option('demo-promotions'));
            $this->info($result['products'].' products imported; '.$result['images'].' local images added.');
            foreach ($result['warnings'] as $warning) {
                $this->warn($warning);
            }

            return $result['warnings'] === [] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function guardDestination(): void
    {
        $configured = config('database.connections.sqlite.database');
        if (app()->environment('testing') && $configured === ':memory:') {
            return;
        }
        $expected = realpath(database_path('database.sqlite'));
        if (! app()->environment('local') || config('storefront.home') !== 'lavaggio' || config('database.default') !== 'sqlite'
            || ! $expected || $configured !== $expected || is_link(database_path('database.sqlite'))) {
            throw new RuntimeException('Product import is restricted to the local Lavaggio SQLite store.');
        }
        $active = collect(DB::connection()->select('PRAGMA database_list'))->firstWhere('name', 'main');
        if (! $active || $active->file !== $expected) {
            throw new RuntimeException('The active database is not the verified local destination.');
        }
    }
}
