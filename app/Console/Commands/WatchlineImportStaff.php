<?php

namespace App\Console\Commands;

use App\Services\Import\WatchlineStaffImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WatchlineImportStaff extends Command
{
    protected $signature = 'watchline:import-staff {--source-connection=watchline_source} {--prefix=oc_} {--dry-run}';

    protected $description = 'Restore original OpenCart staff logins in the isolated local Watchline preview';

    public function handle(WatchlineStaffImportService $importer): int
    {
        try {
            $report = $importer->import(DB::connection((string) $this->option('source-connection')), (string) $this->option('prefix'), ! $this->option('dry-run'));
            $this->table(['Watchline staff', 'Count'], collect($report)->map(fn ($value, $key) => [$key, $value])->all());

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Never include source rows or database exception bindings in console output.
            $this->error('Watchline staff import stopped: '.(get_class($exception) === \RuntimeException::class ? $exception->getMessage() : get_class($exception)));

            return self::FAILURE;
        }
    }
}
