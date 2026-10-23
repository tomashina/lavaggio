<?php

namespace App\Console\Commands;

use App\Services\Integrations\Watchline\WatchlineIntegrationService;
use App\Services\Integrations\Watchline\WatchlineIntegrationSettings;
use Illuminate\Console\Command;

class WatchlineIntegrationsRun extends Command
{
    protected $signature = 'watchline:integrations-run {job? : gath, wolt_catalog, wolt_inventory, reviews, abandoned} {--apply : Primijeni promjene umjesto pregleda} {--due : Izvrši uključene rasporede koji su sada na redu}';

    protected $description = 'Pokretanje Watchline integracija; zadano samo pregled, bez promjene zaliha ili vanjskog slanja.';

    public function handle(WatchlineIntegrationService $integrations): int
    {
        if ($this->option('due')) {
            if (! $this->option('apply')) {
                $this->error('Za rasporede upotrijebite --due --apply. Lokalni sigurnosni način i dalje sprječava izvršavanje.');

                return self::FAILURE;
            }
            $reports = $integrations->runDue();
        } else {
            $job = (string) $this->argument('job');
            if (! isset(WatchlineIntegrationSettings::JOBS[$job])) {
                $this->error('Odaberite jednu integraciju: '.implode(', ', array_keys(WatchlineIntegrationSettings::JOBS)));

                return self::FAILURE;
            }
            $reports = [$job => $integrations->run($job, ! $this->option('apply'), 'cli')];
        }
        foreach ($reports as $job => $report) {
            $this->line($job.': '.$report['status']);
            foreach (($report['reasons'] ?? []) as $reason) {
                $this->line((string) $reason);
            }
        }
        if ($reports === []) {
            $this->info('Nema uključenih integracija koje su sada na redu.');
        }

        return collect($reports)->contains(fn (array $report): bool => in_array($report['status'], ['failed', 'blocked'], true)) ? self::FAILURE : self::SUCCESS;
    }
}
