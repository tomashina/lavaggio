<?php

namespace App\Services\Import;

use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\System\SystemSetting;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WatchlineCheckoutConfigurationService
{
    private const MARKER = 'watchline_checkout';

    private const SETUP_KEY = 'watchline_checkout_configuration';

    public function import(string $connection = 'watchline_source', bool $force = false): array
    {
        $this->assertLocal();
        $source = DB::connection($connection);
        $target = DB::connection();
        if ($source === $target || ($source->getDriverName() === $target->getDriverName()
            && $source->getDatabaseName() === $target->getDatabaseName())) {
            throw new RuntimeException('Watchline checkout requires a separate source snapshot.');
        }
        $this->assertLocalConnection($source);
        $settings = [];
        foreach ($source->table('oc_setting')->where('store_id', 0)->get(['key', 'value', 'serialized']) as $row) {
            $settings[$row->key] = (bool) $row->serialized
                ? json_decode($row->value, true, flags: JSON_THROW_ON_ERROR) : $row->value;
        }
        $snapshot = ['version' => 1, 'settings' => $settings,
            'language_id' => (int) ($settings['config_language_id'] ?? $source->table('oc_language')->where('code', $settings['config_language'] ?? 'hr-hr')->value('language_id') ?? 2)];
        foreach (['geo_zones' => 'geo_zone', 'zone_to_geo_zones' => 'zone_to_geo_zone', 'countries' => 'country',
            'tax_classes' => 'tax_class', 'tax_rules' => 'tax_rule', 'tax_rates' => 'tax_rate'] as $key => $table) {
            $snapshot[$key] = $source->table('oc_'.$table)->get()->map(static fn ($row): array => (array) $row)->all();
        }

        return $this->configure($snapshot, $force);
    }

    /** The snapshot remains private; the returned report contains only public method metadata. */
    public function configure(array $snapshot, bool $force = false): array
    {
        $this->assertLocal();
        if (! is_array($snapshot['settings'] ?? null) || $snapshot['settings'] === []) {
            throw new RuntimeException('The Watchline checkout snapshot has no settings.');
        }
        $settings = $snapshot['settings'];
        $languageId = (int) ($snapshot['language_id'] ?? 2);
        $version = (int) config('watchline_checkout.version', 1);

        $report = DB::transaction(function () use ($snapshot, $settings, $languageId, $force, $version): array {
            $firstSetup = ! SystemSetting::query()->where('key', self::SETUP_KEY)->exists();
            if ($firstSetup || $force) {
                ShippingMethod::query()->update(['is_active' => false]);
                PaymentMethod::query()->update(['is_active' => false]);
            }
            $report = ['shipping_methods' => [], 'payment_methods' => [], 'preserved_admin_edits' => []];
            for ($index = 1; $index <= 12; $index++) {
                $name = trim((string) $this->setting($settings, 'xshipping_name'.$index, ''));
                if ($name === '') {
                    continue;
                }
                $code = match ($index) {
                    1 => 'gls_home', 2 => 'gls_eu', 3 => 'pickup', default => 'watchline_shipping_'.$index
                };
                $pickup = $index === 3;
                $price = $this->money($this->setting($settings, 'xshipping_cost'.$index, 0));
                $freeOver = $this->positiveMoney($this->setting($settings, 'xshipping_free'.$index, 0));
                $geoId = (int) $this->setting($settings, 'xshipping_geo_zone_id'.$index, 0);
                $taxClass = (int) $this->setting($settings, 'xshipping_tax_class_id'.$index, 0);
                $methodSettings = $this->sourceMetadata('xshipping.xshipping'.$index, $this->flag($this->setting($settings, 'xshipping_status'.$index, false)))
                    + ['subtotal_basis' => 'before_cart_discount', 'tax_class_id' => $taxClass,
                        'tax_rate_percent' => $this->taxRate($snapshot, $taxClass)];
                if ($pickup) {
                    $methodSettings += ['pickup_address' => $this->plainText((string) ($settings['config_address'] ?? '')),
                        'pickup_country_code' => 'HR'];
                }
                $attributes = $this->shippingDefaults();
                $attributes = array_replace($attributes, ['name' => $name, 'carrier' => $pickup ? 'pickup' : ($index <= 2 ? 'gls' : 'manual'),
                    'service_type' => $pickup ? 'pickup' : 'home_delivery', 'pricing_type' => $pickup ? 'free' : 'flat',
                    'geo_zone_id' => $this->geoZone($snapshot, $geoId, $force), 'price' => $price, 'free_over' => $freeOver,
                    'is_active' => $this->flag($this->setting($settings, 'xshipping_status', false)) && $methodSettings['source_enabled'],
                    'sort_order' => (int) ($settings['sort_order'.$index] ?? $settings['xshipping_sort_order'.$index] ?? $index * 10),
                    'settings' => $methodSettings]);
                $this->saveMethod(ShippingMethod::class, $code, $attributes, $force, $report, 'shipping_methods');
            }

            $boxNowStatus = $this->flag($this->setting($settings, 'boxnow_status', false));
            $widgetId = trim((string) ($settings['boxnow_widget_partner_id'] ?? $settings['boxnow_partner_id'] ?? ''));
            if ($boxNowStatus || array_key_exists('boxnow_status', $settings)) {
                $boxSettings = $this->sourceMetadata('boxnow', $boxNowStatus) + [
                    'subtotal_basis' => 'before_cart_discount', 'legacy_allow_missing_measurements' => true,
                    'boxnow_partner_id' => $widgetId,
                    'boxnow_api_partner_id' => trim((string) ($settings['boxnow_partner_id'] ?? '')),
                    'boxnow_api_url' => trim((string) ($settings['boxnow_api_url'] ?? '')),
                    'boxnow_oauth_client_id' => trim((string) ($settings['boxnow_client_id'] ?? '')),
                    'boxnow_warehouse_id' => trim((string) ($settings['boxnow_origin_location_id'] ?? '')),
                    'boxnow_compartment_size' => (int) ($settings['boxnow_compartment_size'] ?? 2),
                    'boxnow_allow_return' => $this->flag($settings['boxnow_allow_return'] ?? false),
                    'tax_class_id' => (int) ($settings['boxnow_tax_class_id'] ?? 0),
                    'tax_rate_percent' => $this->taxRate($snapshot, (int) ($settings['boxnow_tax_class_id'] ?? 0)),
                ];
                if (trim((string) ($settings['boxnow_client_secret'] ?? '')) !== '') {
                    $boxSettings['boxnow_oauth_client_secret_encrypted'] = Crypt::encryptString((string) $settings['boxnow_client_secret']);
                }
                $attributes = array_replace($this->shippingDefaults(), ['name' => 'BOX NOW paketomat', 'carrier' => 'boxnow',
                    'service_type' => 'parcel_locker', 'pricing_type' => 'flat',
                    'geo_zone_id' => $this->geoZone($snapshot, (int) ($settings['boxnow_geo_zone_id'] ?? 0), $force),
                    'price' => $this->money($settings['boxnow_cost'] ?? 0), 'free_over' => $this->positiveMoney($settings['boxnow_free_total'] ?? 0),
                    'allows_fragile' => false, 'allows_oversized' => false, 'allows_heavy' => false,
                    'is_active' => $boxNowStatus && $widgetId !== '', 'sort_order' => (int) ($settings['boxnow_sort_order'] ?? 40), 'settings' => $boxSettings]);
                $this->saveMethod(ShippingMethod::class, 'boxnow', $attributes, $force, $report, 'shipping_methods');
            }

            $locker = config('watchline_checkout.gls_locker', []);
            $this->saveMethod(ShippingMethod::class, 'gls_locker', array_replace($this->shippingDefaults(), [
                'name' => 'GLS paketomat', 'carrier' => 'gls', 'service_type' => 'parcel_locker', 'pricing_type' => 'flat',
                'geo_zone_id' => $this->croatianZone($snapshot, $force), 'price' => $this->money($locker['price'] ?? 4.5),
                'free_over' => $this->positiveMoney($locker['free_over'] ?? 70), 'is_active' => (bool) ($locker['enabled'] ?? true),
                'sort_order' => 35, 'settings' => $this->sourceMetadata('additional_gls_locker', false)
                    + ['subtotal_basis' => 'before_cart_discount', 'tax_class_id' => 0, 'tax_rate_percent' => 0,
                        'local_additional_method' => true],
            ]), $force, $report, 'shipping_methods');

            $bankInstructions = (string) ($settings['bank_transfer_bank'.$languageId] ?? $settings['payment_bank_transfer_bank'.$languageId] ?? '');
            $bankSettings = $this->sourceMetadata('bank_transfer', $this->flag($this->setting($settings, 'bank_transfer_status', false)))
                + ['bank_instructions' => $this->plainText($bankInstructions),
                    'default_order_status_id' => $this->statusId((int) $this->setting($settings, 'bank_transfer_order_status_id', 1))];
            foreach (['receiver_name', 'receiver_street', 'receiver_place', 'receiver_iban'] as $key) {
                if (trim((string) data_get($snapshot, 'bank_transfer.'.$key, '')) !== '') {
                    $bankSettings['upi_'.$key] = trim((string) data_get($snapshot, 'bank_transfer.'.$key));
                }
            }
            $this->saveMethod(PaymentMethod::class, 'bank', $this->paymentAttributes($snapshot, $settings, 'bank_transfer',
                'Bankovna uplata / internet bankarstvo', 'bank', $force, $bankSettings, $this->plainText($bankInstructions)), $force, $report, 'payment_methods');
            $this->saveMethod(PaymentMethod::class, 'cod', $this->paymentAttributes($snapshot, $settings, 'cod',
                'Plaćanje pouzećem', 'cod', $force, $this->sourceMetadata('cod', $this->flag($this->setting($settings, 'cod_status', false)))
                    + ['default_order_status_id' => $this->statusId((int) $this->setting($settings, 'cod_order_status_id', 1))]), $force, $report, 'payment_methods');
            $this->saveMethod(PaymentMethod::class, 'pickup', array_replace($this->paymentDefaults(), [
                'name' => 'Plaćanje pri osobnom preuzimanju', 'provider' => 'pickup',
                'description' => 'Plaćanje u Watchline trgovini pri preuzimanju narudžbe.',
                'is_active' => $this->flag($this->setting($settings, 'xshipping_status3', false)), 'sort_order' => 30,
                'settings' => $this->sourceMetadata('pickup_payment', false) + ['local_additional_method' => true],
            ]), $force, $report, 'payment_methods');

            $wspaySettings = $this->sourceMetadata('wspay', $this->flag($this->setting($settings, 'wspay_status', false))) + [
                'wspay_mode' => $this->flag($this->setting($settings, 'wspay_test', true)) ? 'test' : 'live',
                'wspay_shop_id' => trim((string) $this->setting($settings, 'wspay_merchant', '')),
                'wspay_secret_key' => trim((string) $this->setting($settings, 'wspay_password', '')),
                'wspay_return_method' => 'GET', 'local_preview_only' => true,
                'default_order_status_id' => $this->statusId(1),
                'paid_order_status_id' => $this->statusId((int) $this->setting($settings, 'wspay_order_status_id', 5)),
            ];
            $wspay = $this->paymentAttributes($snapshot, $settings, 'wspay', 'Kartično plaćanje (WSPay)', 'wspay', $force, $wspaySettings);
            $wspay['is_active'] = $wspay['is_active'] && $wspaySettings['wspay_shop_id'] !== '' && $wspaySettings['wspay_secret_key'] !== '';
            $this->saveMethod(PaymentMethod::class, 'wspay', $wspay, $force, $report, 'payment_methods');

            $keksSettings = $this->sourceMetadata('kekspay', $this->flag($this->setting($settings, 'kekspay_status', false))) + [
                'keks_mode' => $this->flag($this->setting($settings, 'kekspay_test', true)) ? 'test' : 'live',
                'keks_cid' => trim((string) ($settings['kekspay_cid'] ?? '')), 'keks_tid' => trim((string) ($settings['kekspay_tid'] ?? '')),
                'keks_des_key' => trim((string) ($settings['kekspay_password'] ?? '')), 'keks_qr_type' => 1,
                'local_preview_only' => true,
                'default_order_status_id' => $this->statusId(1),
                'paid_order_status_id' => $this->statusId((int) ($settings['kekspay_order_status_id'] ?? 5)),
            ] + $this->keksCallbackSettings($snapshot, true);
            $keks = $this->paymentAttributes($snapshot, $settings, 'kekspay', 'KEKS Pay', 'keks', $force, $keksSettings);
            $keks['is_active'] = $keks['is_active']
                && $keksSettings['keks_cid'] !== '' && $keksSettings['keks_tid'] !== '' && $keksSettings['keks_des_key'] !== '';
            $this->saveMethod(PaymentMethod::class, 'keks', $keks, $force, $report, 'payment_methods');
            $this->saveMethod(PaymentMethod::class, 'free_checkout', array_replace($this->paymentDefaults(), [
                'name' => 'Narudžba bez naplate', 'provider' => 'free_checkout', 'min_subtotal' => 0, 'max_subtotal' => 0,
                'is_active' => $this->flag($this->setting($settings, 'free_checkout_status', false)),
                'sort_order' => (int) ($settings['free_checkout_sort_order'] ?? 90),
                'settings' => $this->sourceMetadata('free_checkout', $this->flag($this->setting($settings, 'free_checkout_status', false)))
                    + ['default_order_status_id' => $this->statusId((int) ($settings['free_checkout_order_status_id'] ?? 5))],
            ]), $force, $report, 'payment_methods');

            SystemSetting::query()->updateOrCreate(['key' => self::SETUP_KEY], ['value' => json_encode(['version' => $version], JSON_THROW_ON_ERROR)]);

            return $report;
        });
        app(SystemSettingsService::class)->flush();

        return $report;
    }

    /** Update callback authentication without resetting shipping, prices or admin edits. */
    public function updateKeksCallbackAuth(array $snapshot): array
    {
        $this->assertLocal();
        $callback = $this->keksCallbackSettings($snapshot);
        if ($callback['keks_advice_username'] === '' || $callback['keks_advice_password'] === '') {
            throw new RuntimeException('The private Watchline snapshot has no complete KEKS callback credentials.');
        }

        return DB::transaction(function () use ($callback): array {
            $method = PaymentMethod::query()->where('code', 'keks')->firstOrFail();
            $marker = data_get($method->settings, self::MARKER);
            $unchangedImport = is_array($marker)
                && hash_equals((string) ($marker['fingerprint'] ?? ''), $this->fingerprint($method));
            $settings = array_replace((array) $method->settings, $callback);
            unset($settings['keks_advice_token']);
            $method->forceFill(['settings' => $settings])->save();
            if ($unchangedImport) {
                $method->refresh();
                $settings[self::MARKER]['fingerprint'] = $this->fingerprint($method);
                $method->forceFill(['settings' => $settings])->save();
            }

            return ['shipping_methods' => [], 'payment_methods' => [[
                'code' => $method->code, 'name' => $method->name, 'active' => (bool) $method->is_active, 'preserved' => true,
            ]], 'preserved_admin_edits' => []];
        });
    }

    private function keksCallbackSettings(array $snapshot, bool $preserveExisting = false): array
    {
        $callback = (array) ($snapshot['keks_callback'] ?? []);
        $username = is_string($callback['user'] ?? null) ? $callback['user'] : '';
        $password = is_string($callback['pass'] ?? null) ? $callback['pass'] : '';
        if ($preserveExisting && ($username === '' || $password === '')) {
            $existing = (array) PaymentMethod::query()->where('code', 'keks')->value('settings');
            if (($existing['keks_advice_auth_mode'] ?? '') === 'legacy_query_credentials'
                && is_string($existing['keks_advice_username'] ?? null) && $existing['keks_advice_username'] !== ''
                && is_string($existing['keks_advice_password'] ?? null) && $existing['keks_advice_password'] !== '') {
                $username = $existing['keks_advice_username'];
                $password = $existing['keks_advice_password'];
            }
        }

        return ['keks_advice_auth_mode' => 'legacy_query_credentials',
            'keks_advice_username' => $username, 'keks_advice_password' => $password];
    }

    private function saveMethod(string $class, string $code, array $attributes, bool $force, array &$report, string $group): void
    {
        $model = $class::query()->firstOrNew(['code' => $code]);
        $marker = data_get($model->settings, self::MARKER);
        if ($model->exists && is_array($marker) && ! $force
            && ! hash_equals((string) ($marker['fingerprint'] ?? ''), $this->fingerprint($model))) {
            $report['preserved_admin_edits'][] = $code;
            $report[$group][] = ['code' => $code, 'name' => $model->name, 'active' => (bool) $model->is_active, 'preserved' => true];

            return;
        }
        $model->fill($attributes)->save();
        $model->refresh();
        $settings = (array) $model->settings;
        $settings[self::MARKER] = ['version' => (int) config('watchline_checkout.version', 1), 'fingerprint' => $this->fingerprint($model)];
        $model->forceFill(['settings' => $settings])->save();
        $report[$group][] = ['code' => $code, 'name' => $model->name, 'active' => (bool) $model->is_active, 'preserved' => false];
    }

    private function fingerprint(Model $model): string
    {
        $values = [];
        foreach ($model->getFillable() as $key) {
            $value = $model->getAttribute($key);
            if ($key === 'settings' && is_array($value)) {
                unset($value[self::MARKER]);
            }
            $values[$key] = $value;
        }
        $sort = function (array $value) use (&$sort): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $sort($item);
                }
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($values), JSON_THROW_ON_ERROR));
    }

    private function paymentAttributes(array $snapshot, array $settings, string $prefix, string $name, string $provider, bool $force, array $methodSettings, string $description = ''): array
    {
        return array_replace($this->paymentDefaults(), ['name' => $name, 'provider' => $provider, 'description' => $description,
            'geo_zone_id' => $this->geoZone($snapshot, (int) $this->setting($settings, $prefix.'_geo_zone_id', 0), $force),
            'min_subtotal' => $this->positiveMoney($this->setting($settings, $prefix.'_total', 0)),
            'is_active' => $this->flag($this->setting($settings, $prefix.'_status', false)),
            'sort_order' => (int) $this->setting($settings, $prefix.'_sort_order', 10), 'settings' => $methodSettings]);
    }

    private function shippingDefaults(): array
    {
        return ['description' => '', 'geo_zone_id' => null, 'price' => 0, 'free_over' => null,
            'min_subtotal' => null, 'max_subtotal' => null, 'min_weight_kg' => null, 'max_weight_kg' => null,
            'max_length_cm' => null, 'max_width_cm' => null, 'max_height_cm' => null,
            'allows_fragile' => true, 'allows_oversized' => true, 'allows_heavy' => true,
            'fragile_surcharge' => 0, 'oversized_surcharge' => 0, 'heavy_surcharge' => 0, 'missing_measurements_policy' => 'allow'];
    }

    private function paymentDefaults(): array
    {
        return ['geo_zone_id' => null, 'description' => '', 'fee_type' => 'fixed', 'fee_value' => 0, 'min_subtotal' => null, 'max_subtotal' => null];
    }

    private function sourceMetadata(string $method, bool $enabled): array
    {
        return ['configured_from' => 'watchline-opencart', 'source_method' => $method, 'source_enabled' => $enabled];
    }

    private function geoZone(array $snapshot, int $sourceId, bool $force): ?int
    {
        if ($sourceId <= 0) {
            return null;
        }
        $source = collect($snapshot['geo_zones'] ?? [])->first(fn ($row): bool => (int) ($row['source_id'] ?? $row['geo_zone_id'] ?? 0) === $sourceId);
        $countries = (array) ($source['countries'] ?? []);
        if ($countries === []) {
            $ids = collect($snapshot['zone_to_geo_zones'] ?? [])->where('geo_zone_id', $sourceId)->pluck('country_id');
            $countries = collect($snapshot['countries'] ?? [])->whereIn('country_id', $ids)->pluck('iso_code_2')->all();
        }
        $countries = array_values(array_unique(array_filter(array_map('strtoupper', $countries), fn ($code): bool => preg_match('/^[A-Z]{2}$/', $code) === 1)));
        if ($countries === []) {
            throw new RuntimeException('A Watchline delivery or payment zone has no destination countries.');
        }
        $zone = GeoZone::query()->firstOrNew(['code' => 'watchline-oc-zone-'.$sourceId]);
        if (! $zone->exists || $force) {
            $zone->fill(['name' => 'Watchline — '.($source['name'] ?? implode(', ', $countries)), 'description' => 'Odredišta iz Watchline trgovine.', 'is_active' => true, 'sort_order' => $sourceId])->save();
            GeoZoneCountry::query()->where('geo_zone_id', $zone->id)->delete();
            foreach ($countries as $country) {
                GeoZoneCountry::query()->create(['geo_zone_id' => $zone->id, 'country_code' => $country]);
            }
        }

        return (int) $zone->id;
    }

    private function croatianZone(array $snapshot, bool $force): int
    {
        $snapshot['geo_zones'][] = ['source_id' => 900001, 'name' => 'Hrvatska', 'countries' => ['HR']];

        return $this->geoZone($snapshot, 900001, $force);
    }

    private function taxRate(array $snapshot, int $taxClassId): float
    {
        if ($taxClassId <= 0) {
            return 0;
        }
        $class = collect($snapshot['tax_classes'] ?? [])->first(fn ($row): bool => (int) ($row['source_id'] ?? $row['tax_class_id'] ?? 0) === $taxClassId);
        if (is_numeric($class['rate_percent'] ?? null)) {
            return (float) $class['rate_percent'];
        }
        $ids = collect($snapshot['tax_rules'] ?? [])->where('tax_class_id', $taxClassId)->pluck('tax_rate_id');
        $rates = collect($snapshot['tax_rates'] ?? [])->whereIn('tax_rate_id', $ids);
        if ($rates->isEmpty() || $rates->contains(fn ($rate): bool => ($rate['type'] ?? '') !== 'P')) {
            throw new RuntimeException('An active Watchline shipping tax class cannot be represented safely.');
        }

        return (float) $rates->sum('rate');
    }

    private function statusId(int $sourceId): ?int
    {
        return OrderStatus::query()->whereIn('code', ['herrera-oc-status-'.$sourceId, 'watchline-oc-status-'.$sourceId])->value('id')
            ?? OrderStatus::query()->where('is_active', true)->orderByDesc('is_default')->value('id');
    }

    private function setting(array $settings, string $key, mixed $default): mixed
    {
        return $settings[$key] ?? $settings['shipping_'.$key] ?? $settings['payment_'.$key] ?? $default;
    }

    private function flag(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function money(mixed $value): float
    {
        $value = is_string($value) ? str_replace(',', '.', trim($value)) : $value;
        if (! is_numeric($value) || (float) $value < 0) {
            throw new RuntimeException('The Watchline checkout snapshot contains an invalid amount.');
        }

        return round((float) $value, 2);
    }

    private function positiveMoney(mixed $value): ?float
    {
        return $value === '' || $value === null || $this->money($value) <= 0 ? null : $this->money($value);
    }

    private function plainText(string $value): string
    {
        return trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?\s*>/i', "\n", $value)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function assertLocal(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Watchline checkout configuration is restricted to local preview and tests.');
        }
        $this->assertLocalConnection(DB::connection());
    }

    private function assertLocalConnection($connection): void
    {
        if ($connection->getDriverName() !== 'sqlite'
            && ! in_array($connection->getConfig('host'), ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Watchline checkout configuration requires local databases.');
        }
    }
}
