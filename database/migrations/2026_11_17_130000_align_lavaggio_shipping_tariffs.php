<?php

use App\Models\Settings\Local\ShippingMethod;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('storefront.home') !== 'lavaggio') {
            return;
        }

        DB::transaction(function (): void {
            $home = ShippingMethod::where('code', 'gls_home')->first();
            // One-time initial values. All runtime prices and mappings come from admin settings.
            $tariffs = [
                'gls_home' => ['sku' => '100001', 'name' => 'GLS – dostava na adresu (do 40 kg)', 'net' => 4.72, 'min' => null, 'max' => 40, 'free' => true, 'carrier' => 'gls', 'service' => 'home_delivery'],
                'gls_locker' => ['sku' => '100002', 'name' => 'GLS – dostava na paketomat (do 20 kg)', 'net' => 3.92, 'min' => null, 'max' => 20, 'free' => true, 'carrier' => 'gls', 'service' => 'parcel_locker'],
                'boxnow' => ['sku' => '100003', 'name' => 'BOX NOW – dostava na paketomat (do 20 kg)', 'net' => 3.12, 'min' => null, 'max' => 20, 'free' => true, 'carrier' => 'boxnow', 'service' => 'parcel_locker'],
                'gls_heavy' => ['sku' => '100004', 'name' => 'GLS – dostava na adresu preko 40 kg do 80 kg', 'net' => 19.92, 'min' => 40.001, 'max' => 80, 'free' => false, 'carrier' => 'gls', 'service' => 'home_delivery'],
                'pallet' => ['sku' => '100005', 'name' => 'Paletna dostava po Hrvatskoj – više od 80 kg', 'net' => 79.92, 'min' => 80.001, 'max' => null, 'free' => false, 'carrier' => 'manual', 'service' => 'home_delivery'],
            ];
            foreach ($tariffs as $code => $tariff) {
                $method = ShippingMethod::firstOrNew(['code' => $code]);
                if (! $method->exists) {
                    $method->fill([
                        'geo_zone_id' => $home?->geo_zone_id,
                        'is_active' => true, 'allows_fragile' => true, 'allows_oversized' => true, 'allows_heavy' => true,
                        'settings' => ['destination_country_codes' => ['HR']],
                        'sort_order' => $code === 'gls_heavy' ? 40 : 50,
                    ]);
                }
                $settings = is_array($method->settings) ? $method->settings : [];
                unset($settings['opencart_xshippingpro']);
                $method->fill([
                    'name' => $tariff['name'], 'carrier' => $tariff['carrier'], 'service_type' => $tariff['service'],
                    'pricing_type' => 'flat', 'price' => $tariff['net'], 'free_over' => $tariff['free'] ? 70 : null,
                    'min_weight_kg' => $tariff['min'], 'max_weight_kg' => $tariff['max'],
                    'min_subtotal' => null, 'max_subtotal' => null,
                    'fragile_surcharge' => 0, 'oversized_surcharge' => 0, 'heavy_surcharge' => 0,
                    'missing_measurements_policy' => $method->missing_measurements_policy ?: 'allow',
                    'settings' => array_replace($settings, [
                        'tax_rate_percent' => 25, 'fira_product_code' => $tariff['sku'],
                        'fira_kpd_code' => '53.20.09',
                        'free_shipping_disabled' => ! $tariff['free'], 'free_shipping_strict' => true,
                        'subtotal_basis' => 'gross_after_discount', 'require_known_weight' => true,
                    ]),
                ])->save();
            }
            app(SystemSettingsService::class)->putMany([
                'lavaggio_free_shipping_threshold' => 70,
                'store_announcement_text' => 'Besplatna dostava za narudžbe iznad 70 €',
            ]);
        });
    }

    public function down(): void
    {
        // Keep current admin decisions and order snapshots; restoring rates is an explicit action.
    }
};
