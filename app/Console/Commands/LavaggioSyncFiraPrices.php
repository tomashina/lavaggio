<?php

namespace App\Console\Commands;

use App\Services\Integrations\Fira\FiraPriceSyncService;
use App\Services\Integrations\Fira\FiraSettingsService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class LavaggioSyncFiraPrices extends Command
{
    protected $signature = 'lavaggio:sync-fira-prices {--apply : Save matched FIRA prices} {--scheduled : Respect the automatic sync setting}';

    protected $description = 'Preview or update existing product and option prices from FIRA by SKU';

    public function handle(FiraPriceSyncService $sync): int
    {
        if ($this->option('scheduled') && (config('storefront.home') !== 'lavaggio'
            || app()->environment(['local', 'testing'])
            || ! app(FiraSettingsService::class)->options()['price_sync_enabled'])) {
            return self::SUCCESS;
        }

        try {
            $result = $sync->sync((bool) $this->option('apply'));
            $this->info(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->warn('Zalihe nisu sinkronizirane: dokumentirani FIRA API ne vraća skladišne količine.');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
