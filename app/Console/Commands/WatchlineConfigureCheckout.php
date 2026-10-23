<?php

namespace App\Console\Commands;

use App\Services\Import\WatchlineCheckoutConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class WatchlineConfigureCheckout extends Command
{
    protected $signature = 'watchline:configure-checkout
        {--source-connection=watchline_source : Isolated read-only OpenCart snapshot connection}
        {--snapshot= : Private JSON snapshot path instead of a database connection}
        {--keks-callback-only : Update only KEKS callback authentication from the private snapshot}
        {--force : Replace previously imported checkout settings, including later admin changes}';

    protected $description = 'Configure local Watchline delivery and payments from its private legacy snapshot';

    public function handle(WatchlineCheckoutConfigurationService $configuration): int
    {
        try {
            $path = trim((string) $this->option('snapshot'));
            if ($path !== '') {
                $path = str_starts_with($path, '/') ? $path : Storage::disk('local')->path($path);
                $snapshot = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $report = $this->option('keks-callback-only')
                    ? $configuration->updateKeksCallbackAuth($snapshot)
                    : $configuration->configure($snapshot, (bool) $this->option('force'));
            } else {
                if ($this->option('keks-callback-only')) {
                    $this->error('Za ovu izmjenu potreban je privatni JSON snapshot.');

                    return self::FAILURE;
                }
                $report = $configuration->import((string) $this->option('source-connection'), (bool) $this->option('force'));
            }
            foreach (['shipping_methods' => 'Dostava', 'payment_methods' => 'Plaćanje'] as $group => $label) {
                $this->table([$label, 'Status', 'Postavke'], collect($report[$group])->map(fn (array $method): array => [
                    $method['name'], $method['active'] ? 'Aktivno' : 'Neaktivno', $method['preserved'] ? 'Sačuvane ručne izmjene' : 'Konfigurirano',
                ])->all());
            }
            $this->info('Lokalni Watchline checkout je konfiguriran. Vanjski servisi nisu pozivani.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Database and snapshot exceptions may contain credentials; never echo them.
            $this->error('Konfiguracija nije dovršena. Provjerite privatni snapshot i lokalnu bazu; promjene su poništene.');

            return self::FAILURE;
        }
    }
}
