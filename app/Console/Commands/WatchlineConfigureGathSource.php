<?php

namespace App\Console\Commands;

use App\Services\Integrations\Watchline\GathLegacySourceImporter;
use Illuminate\Console\Command;

class WatchlineConfigureGathSource extends Command
{
    protected $signature = 'watchline:configure-gath-source {source_root : Existing local OpenCart project directory}';

    protected $description = 'Privately copy the legacy GATH CSV and encrypt diagnostic connection settings without making requests';

    public function handle(GathLegacySourceImporter $importer): int
    {
        try {
            $report = $importer->import((string) $this->argument('source_root'));
            $this->line('Privatni CSV: '.$report['csv_path']);
            $this->line('Redaka: '.$report['fetched_count'].'. SHA-256: '.$report['checksum']);
            $this->line('Dijagnostičke pristupne postavke: '.($report['credentials_present'] ? 'šifrirane u privatnom spremištu' : 'nisu pronađene'));
            $this->info('Izvorni projekt i katalog nisu mijenjani. Vanjski servisi nisu pozivani.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Privatni prijenos GATH izvora nije dovršen. Provjerite lokalni izvor i dopuštenja spremišta.');

            return self::FAILURE;
        }
    }
}
