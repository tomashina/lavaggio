<?php

namespace App\Services\Import;

use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Payments\EpcQrService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LavaggioCheckoutConfigurationService
{
    /** Configure only the dedicated local Lavaggio preview and preserve later admin edits. */
    public function configure(): array
    {
        if (! app()->environment(['local', 'testing']) || config('storefront.home') !== 'lavaggio'
            || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Checkout setup is restricted to the local Lavaggio SQLite preview.');
        }

        return DB::transaction(function (): array {
            $zone = GeoZone::firstOrCreate(['code' => 'lavaggio-hr'], [
                'name' => 'Lavaggio – Hrvatska', 'description' => 'Odredišta Lavaggio dostave.',
                'is_active' => true, 'sort_order' => 10,
            ]);
            GeoZoneCountry::firstOrCreate(['geo_zone_id' => $zone->id, 'country_code' => 'HR']);
            $legacy = ShippingMethod::where('code', 'lavaggio-local-delivery')->first();
            $threshold = app(SystemSettingsService::class)->get('lavaggio_free_shipping_threshold', $legacy?->free_over);
            $report = ['shipping_methods' => [], 'payment_methods' => [], 'missing' => []];

            foreach ([
                ['gls_home', 'GLS – dostava na adresu', 'gls', 'home_delivery', 'Dostava GLS kurirskom službom na odabranu adresu.'],
                ['gls_locker', 'GLS paketomat', 'gls', 'parcel_locker', 'Odaberite GLS paketomat na karti tijekom narudžbe.'],
                ['boxnow', 'BOX NOW paketomat', 'boxnow', 'parcel_locker', 'Odaberite BOX NOW paketomat na karti tijekom narudžbe.'],
            ] as $index => [$code, $name, $carrier, $service, $description]) {
                $price = config('lavaggio_checkout.'.$code.'_price', $legacy?->price);
                $price = is_numeric($price) ? (float) $price : (is_numeric($legacy?->price) ? (float) $legacy->price : null);
                $existing = ShippingMethod::where('code', $code)->first();
                if (! $existing) {
                    $settings = ['configured_from' => 'lavaggio-checkout', 'destination_country_codes' => ['HR'],
                        'tariff_inherited_from' => $legacy && config('lavaggio_checkout.'.$code.'_price') === null ? $legacy->code : null];
                    $limits = [];
                    if ($carrier === 'boxnow') {
                        // Official BOX NOW large compartment limits; partner ID is optional for its public map.
                        $settings += ['boxnow_partner_id' => (string) config('lavaggio_checkout.boxnow_partner_id'),
                            'boxnow_public_widget' => true, 'boxnow_compartment_size' => 3,
                            'local_preview_allow_missing_measurements' => true];
                        $limits = ['max_weight_kg' => 20, 'max_length_cm' => 60, 'max_width_cm' => 45, 'max_height_cm' => 36,
                            'allows_fragile' => false, 'allows_oversized' => false, 'allows_heavy' => false];
                    }
                    $existing = ShippingMethod::create($limits + [
                        'code' => $code, 'name' => $name, 'description' => $description, 'carrier' => $carrier,
                        'service_type' => $service, 'pricing_type' => 'flat', 'geo_zone_id' => $zone->id,
                        'price' => $price ?? 0, 'free_over' => is_numeric($threshold) ? (float) $threshold : null,
                        'allows_fragile' => true, 'allows_oversized' => true, 'allows_heavy' => true,
                        'missing_measurements_policy' => $carrier === 'boxnow' ? 'block' : 'allow',
                        'is_active' => $price !== null, 'sort_order' => ($index + 1) * 10, 'settings' => $settings,
                    ]);
                }
                $report['shipping_methods'][] = ['code' => $code, 'name' => $existing->name, 'active' => $existing->is_active];
                if ($price === null && ! $existing->is_active) {
                    $report['missing'][] = 'Cijena dostave: '.$name;
                }
            }
            if ($legacy && data_get($legacy->settings, 'local_demo') === true) {
                $legacy->update(['is_active' => false]);
            }

            $status = OrderStatus::where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->value('id');
            $cod = PaymentMethod::firstOrCreate(['code' => 'cod'], [
                'name' => 'Pouzećem', 'provider' => 'cod', 'description' => 'Platite narudžbu pri preuzimanju pošiljke.',
                'geo_zone_id' => $zone->id, 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true, 'sort_order' => 10,
                'settings' => ['configured_from' => 'lavaggio-checkout', 'default_order_status_id' => $status],
            ]);
            $bank = config('lavaggio_checkout.bank', []);
            $bankReady = app(EpcQrService::class)->validIban((string) ($bank['receiver_iban'] ?? ''));
            $bankMethod = PaymentMethod::firstOrCreate(['code' => 'bank'], [
                'name' => 'Bankovna transakcija', 'provider' => 'bank',
                'description' => 'Uplatite na račun bankovnom transakcijom. Podaci za uplatu i QR kod nalaze se u potvrdi narudžbe.',
                'geo_zone_id' => $zone->id, 'fee_type' => 'fixed', 'fee_value' => 0,
                'is_active' => $bankReady || (bool) config('commerce.local_safe_mode'), 'sort_order' => 20,
                'settings' => ['configured_from' => 'lavaggio-checkout', 'default_order_status_id' => $status,
                    'bank_local_codes' => true, 'bank_preview_placeholder' => true,
                    'bank_instructions' => 'Molimo uplatite točan iznos narudžbe. U pozivu na broj navedite referencu iz potvrde narudžbe.',
                    'upi_receiver_name' => (string) ($bank['receiver_name'] ?? ''), 'upi_receiver_street' => (string) ($bank['receiver_street'] ?? ''),
                    'upi_receiver_place' => (string) ($bank['receiver_place'] ?? ''), 'upi_receiver_iban' => (string) ($bank['receiver_iban'] ?? ''),
                    'upi_model' => '00', 'upi_purpose_code' => 'SUPP', 'upi_description' => 'Lavaggio narudžba'],
            ]);
            foreach ([$cod, $bankMethod] as $method) {
                $report['payment_methods'][] = ['code' => $method->code, 'name' => $method->name, 'active' => $method->is_active];
            }
            if (! app(EpcQrService::class)->validIban((string) data_get($bankMethod->settings, 'upi_receiver_iban', ''))) {
                $report['missing'][] = 'Ogledni IBAN XXXX: nije za uplatu. Stvarni IBAN upisuje se pod Postavke → Lokalno → Načini plaćanja.';
            }

            return $report;
        });
    }
}
