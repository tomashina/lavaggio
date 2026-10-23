<?php

namespace App\Console\Commands;

use App\Services\Import\LibermediaSupplementImportService;
use Illuminate\Console\Command;

class LibermediaImportSupplement extends Command
{
    protected $signature = 'libermedia:import-supplement {--dry-run}';

    protected $description = 'Import Liber Media reviews, bundles, payment and shipping methods from the local snapshot';

    public function handle(LibermediaSupplementImportService $service): int
    {
        $stats = $service->import(dryRun: (bool) $this->option('dry-run'));
        $this->table(['Records', 'Count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->all());
        if (! $this->option('dry-run')) {
            \Illuminate\Support\Facades\Storage::disk('local')->put('libermedia-migration/supplement-report.json', json_encode($stats, JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
