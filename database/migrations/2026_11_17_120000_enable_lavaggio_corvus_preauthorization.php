<?php

use App\Models\Settings\Local\PaymentMethod;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            return;
        }

        PaymentMethod::query()->whereIn('code', ['corvus', 'corvuspay', 'corvus_pay'])->each(function (PaymentMethod $method): void {
            $settings = is_array($method->settings) ? $method->settings : [];
            $settings['corvus_require_complete'] = 'true';
            $method->update(['settings' => $settings]);
        });
    }

    public function down(): void
    {
        // Do not silently re-enable immediate charges or overwrite later admin choices.
    }
};
