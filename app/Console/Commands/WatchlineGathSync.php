<?php

namespace App\Console\Commands;

use App\Services\Integrations\Watchline\GathSyncService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Console\Command;

class WatchlineGathSync extends Command
{
    protected $signature = 'watchline:gath-sync
        {--file= : Local GATH CSV file; defaults to the integration setting}
        {--apply : Apply prices and own stock after reviewing the preview}
        {--reset-missing : Explicitly zero absent imported or GATH-managed own stock}';

    protected $description = 'Preview or apply the local Watchline GATH price and own-stock CSV';

    public function handle(GathSyncService $sync, SystemSettingsService $settings): int
    {
        try {
            $configuration = $settings->get('watchline_integrations', []);
            $path = trim((string) ($this->option('file') ?: ($configuration['gath_csv_path'] ?? '')));
            if ($path !== '' && ! str_starts_with($path, '/') && ! str_contains($path, '://')) {
                $path = is_file(base_path($path)) ? base_path($path) : storage_path('app/private/'.$path);
            }
            $report = $this->option('apply')
                ? $sync->apply($path, (bool) $this->option('reset-missing'))
                : $sync->preview($path);
            $this->table(['Rezultat', 'Broj'], [
                ['Redaka CSV', $report['fetched_count']], ['Povezanih artikala', $report['matched_count']],
                ['Promjena cijene', $report['price_changed_count']], ['Promjena zalihe', $report['stock_changed_count']],
                ['Ponovno aktiviranje', $report['reactivated_count']], ['Nepovezanih SKU oznaka', $report['unmatched_count']],
                ['Odsutnih artikala sa zalihom', $report['reset_candidate_count']],
            ]);
            if (! $report['can_apply']) {
                $this->error('Nijedan artikl nije povezan. Primjena je blokirana.');

                return self::FAILURE;
            }
            $this->info($this->option('apply')
                ? 'GATH cijene i vlastita zaliha spremljeni su. Zapis izvršavanja: '.$report['run_id'].'.'
                : 'Pregled je dovršen. Cijene i zalihe nisu mijenjane.');
            if ($this->option('reset-missing') && ! $this->option('apply')) {
                $this->warn('Poništavanje odsutnih zaliha primjenjuje se tek uz --apply --reset-missing.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error(get_class($exception) === \RuntimeException::class
                ? $exception->getMessage() : 'GATH uvoz nije dovršen. Provjerite lokalnu datoteku i bazu podataka.');

            return self::FAILURE;
        }
    }
}
