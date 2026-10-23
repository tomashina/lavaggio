<?php

namespace App\Support;

use App\Models\Settings\Local\ShippingMethod;

class LibermediaShippingBenefit
{
    /** @return array{title: string, description: string} */
    public function forCroatia(): array
    {
        $offers = ShippingMethod::query()->where('is_active', true)
            ->where('service_type', 'home_delivery')->where('pricing_type', '!=', 'quote')
            ->with('geoZone.countries')->get()
            ->map(function (ShippingMethod $method): ?array {
                $settings = (array) $method->settings;
                $countries = array_map(fn ($code) => strtoupper(trim((string) $code)), (array) ($settings['destination_country_codes'] ?? []));
                $destinationScopes = (array) ($settings['destination_scopes'] ?? []);
                if (($countries !== [] && ! in_array('HR', $countries, true))
                    || ($method->geo_zone_id && ! $method->geoZone?->countries->contains('country_code', 'HR'))
                    || ($settings['destination_scope'] ?? '') === 'hr_islands'
                    || ($destinationScopes !== [] && ! in_array('hr_mainland', $destinationScopes, true))) {
                    return null;
                }

                $legacy = $settings['opencart_xshippingpro'] ?? null;
                $exclusivePostal = is_array($legacy) && ($legacy['postal_rule'] ?? '') === 'exclusive'
                    && ($legacy['postal_all'] ?? '0') !== '1' && trim((string) ($legacy['postal'] ?? '')) !== '';
                if (is_array($legacy) && ! $exclusivePostal
                    && ($legacy['postal_all'] ?? '0') !== '1' && trim((string) ($legacy['postal'] ?? '')) !== '') {
                    return null;
                }

                $threshold = null;
                $exclusive = false;
                if (is_array($legacy)) {
                    // Imported range prices override free_over in the checkout calculator.
                    $ranges = collect($legacy['ranges'] ?? [])->filter(fn ($range) => is_numeric($range['start'] ?? null)
                        && is_numeric($range['end'] ?? null) && is_numeric($range['cost'] ?? null))
                        ->sortBy('start')->values();
                    $last = $ranges->last();
                    if ($last && (float) $last['cost'] === 0.0) {
                        $firstFreeIndex = $ranges->count() - 1;
                        while ($firstFreeIndex > 0 && (float) $ranges[$firstFreeIndex - 1]['cost'] === 0.0
                            && round((float) $ranges[$firstFreeIndex - 1]['end'] + .01, 2) >= (float) $ranges[$firstFreeIndex]['start']) {
                            $firstFreeIndex--;
                        }
                        $last = $ranges[$firstFreeIndex];
                        $threshold = round((float) $last['start'], 2);
                        $previous = $ranges->get($firstFreeIndex - 1);
                        if ($previous && round((float) $previous['end'] + .01, 2) === $threshold) {
                            $threshold = round((float) $previous['end'], 2);
                            $exclusive = true;
                        }
                    }
                } elseif ($method->pricing_type === 'free' || (float) $method->price === 0.0 && $method->pricing_type === 'flat') {
                    $threshold = max(0, (float) ($method->min_subtotal ?? 0));
                } elseif (is_numeric($method->free_over)) {
                    $threshold = max(0, (float) $method->free_over, (float) ($method->min_subtotal ?? 0));
                }
                if ($threshold === null || $method->max_subtotal !== null) {
                    return null;
                }
                $mainlandOnly = $exclusivePostal || ($settings['destination_scope'] ?? '') === 'hr_mainland'
                    || ($destinationScopes !== [] && ! in_array('hr_islands', $destinationScopes, true));

                return ['threshold' => $threshold, 'exclusive' => $exclusive, 'mainland' => $mainlandOnly];
            })->filter()->sortBy('threshold');
        $offer = $offers->first();
        if (! $offer) {
            return ['title' => 'Dostava', 'description' => 'Provjerite mogućnosti dostave'];
        }

        $amount = rtrim(rtrim(number_format($offer['threshold'], 2, ',', '.'), '0'), ',');
        $area = $offer['mainland'] ? 'u HR, osim otoka' : 'u Hrvatskoj';

        return [
            'title' => 'Besplatna dostava',
            'description' => $offer['threshold'] > 0
                ? ($offer['exclusive'] ? 'Iznad ' : 'Od ').$amount.' € '.$area
                : 'Za narudžbe '.$area,
        ];
    }
}
