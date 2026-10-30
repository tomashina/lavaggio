<?php

namespace App\Console\Commands;

use App\Services\Import\LavaggioCheckoutConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class LavaggioConfigureCheckout extends Command
{
    protected $signature = 'lavaggio:configure-checkout';

    protected $description = 'Configure Lavaggio delivery and payments, preserving existing tariffs and admin edits';

    public function handle(LavaggioCheckoutConfigurationService $configuration): int
    {
        if (! app()->environment('local') || config('storefront.home') !== 'lavaggio'
            || DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Restricted to the local Lavaggio SQLite preview.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private/lavaggio-checkout-backups');
        File::ensureDirectoryExists($directory, 0700);
        $backup = $directory.'/checkout-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
        DB::connection()->getPdo()->exec("VACUUM INTO '".str_replace("'", "''", $backup)."'");
        chmod($backup, 0600);
        $report = $configuration->configure();
        foreach (['shipping_methods' => 'Dostava', 'payment_methods' => 'Plaćanje'] as $group => $label) {
            $this->table([$label, 'Status'], array_map(static fn (array $method): array => [
                $method['name'], $method['active'] ? 'Aktivno' : 'Čeka postavke',
            ], $report[$group]));
        }
        foreach ($report['missing'] as $missing) {
            $this->warn($missing);
        }
        $this->info('Lavaggio checkout je konfiguriran. Postojeće cijene i naknadne izmjene su sačuvane.');

        return self::SUCCESS;
    }
}
