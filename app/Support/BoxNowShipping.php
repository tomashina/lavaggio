<?php

namespace App\Support;

use App\Models\Settings\Local\ShippingMethod;

class BoxNowShipping
{
    public static function preservesLegacyAvailability(ShippingMethod $method): bool
    {
        return data_get($method->settings, 'configured_from') === 'watchline-opencart'
            && data_get($method->settings, 'source_method') === 'boxnow'
            && data_get($method->settings, 'legacy_allow_missing_measurements') === true;
    }

    public static function isReady(ShippingMethod $method): bool
    {
        $legacyMeasurements = self::preservesLegacyAvailability($method)
            && (string) $method->missing_measurements_policy === 'allow';
        $measuredParcels = (string) $method->missing_measurements_policy === 'block'
            && (float) $method->max_weight_kg > 0
            && (float) $method->max_length_cm > 0
            && (float) $method->max_width_cm > 0
            && (float) $method->max_height_cm > 0;

        return trim((string) data_get($method->settings, 'boxnow_partner_id', '')) !== ''
            && (string) $method->service_type === 'parcel_locker'
            && (string) $method->pricing_type === 'flat'
            && ($legacyMeasurements || $measuredParcels)
            && ! (bool) $method->allows_fragile
            && ! (bool) $method->allows_oversized
            && ! (bool) $method->allows_heavy;
    }
}
