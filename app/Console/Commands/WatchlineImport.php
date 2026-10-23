<?php

namespace App\Console\Commands;

use App\Services\Import\HerreraOpenCartImportService;
use App\Services\Import\WatchlineLegacySalesImportService;
use App\Services\Import\WatchlineStaffImportService;
use App\Services\Import\WatchlineSupplementImportService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WatchlineImport extends HerreraImport
{
    protected $signature = 'watchline:import {--source-connection=watchline_source} {--prefix=oc_} {--only=catalog,content,customers,billing_addresses,pricing,orders,seo,relations,archive} {--dry-run} {--final-sync} {--supplement-only : Import extension records after the main migration}';

    protected $description = 'Import an isolated local Watchline OpenCart snapshot into this application';

    public function handle(HerreraOpenCartImportService $importer): int
    {
        try {
            // One target transaction avoids thousands of individual SQLite disk flushes.
            return DB::transaction(function () use ($importer): int {
                if (! $this->option('supplement-only') && parent::handle($importer) !== self::SUCCESS) {
                    throw new RuntimeException('The main Watchline import did not complete; target changes were rolled back.');
                }
                if ($this->option('final-sync') || (! $this->option('supplement-only') && $this->option('only') !== 'catalog,content,customers,billing_addresses,pricing,orders,seo,relations,archive')) {
                    return self::SUCCESS;
                }
                $connection = (string) $this->option('source-connection');
                $dryRun = (bool) $this->option('dry-run');
                $summary = app(WatchlineSupplementImportService::class)->import($connection, $dryRun, (string) $this->option('prefix'));
                $summary += app(WatchlineLegacySalesImportService::class)->import($connection, $dryRun);
                $staff = app(WatchlineStaffImportService::class)->import(DB::connection($connection), (string) $this->option('prefix'), ! $dryRun);
                foreach ($staff as $key => $count) {
                    $summary['staff_'.$key] = $count;
                }
                $this->table(['Additional Watchline records', 'Count'], collect($summary)->map(fn ($value, $key) => [$key, $value])->all());
                if (! $dryRun) {
                    foreach (['watchline:normalize-content', 'watchline:import-promotions'] as $command) {
                        if ($this->getApplication()->has($command) && $this->call($command) !== self::SUCCESS) {
                            throw new RuntimeException('Watchline content or promotions setup failed; target changes were rolled back.');
                        }
                    }
                }

                return self::SUCCESS;
            });
        } catch (\Throwable $exception) {
            $this->error('Watchline import stopped: '.(get_class($exception) === RuntimeException::class ? $exception->getMessage() : get_class($exception)));
            \Illuminate\Support\Facades\Log::error('Watchline supplemental import stopped', ['exception_class' => get_class($exception)]);

            return self::FAILURE;
        }
    }
}
