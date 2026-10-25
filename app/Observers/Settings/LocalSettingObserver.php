<?php

namespace App\Observers\Settings;

use App\Models\Settings\Local\TaxRate;
use App\Services\Settings\LocalSettingsService;
use Illuminate\Database\Eloquent\Model;

class LocalSettingObserver
{
    public function saved(Model $model): void
    {
        app(LocalSettingsService::class)->forget($model::class);
        $this->forgetTaxRate($model);
    }

    public function deleted(Model $model): void
    {
        app(LocalSettingsService::class)->forget($model::class);
        $this->forgetTaxRate($model);
    }

    private function forgetTaxRate(Model $model): void
    {
        if ($model instanceof TaxRate) {
            request()->attributes->remove('pricing.default-active-tax-rate');
        }
    }
}
