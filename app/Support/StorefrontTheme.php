<?php

namespace App\Support;

use App\Services\Settings\SystemSettingsService;

class StorefrontTheme
{
    public static function usesLibermediaBrand(?string $storeName = null): bool
    {
        $brand = strtolower(trim((string) config('storefront.brand', 'auto')));
        if ($brand !== '' && $brand !== 'auto') {
            return $brand === 'libermedia';
        }

        $storeName ??= (string) app(SystemSettingsService::class)
            ->get('store_brand_name', config('app.name', 'AG Shop'));

        return str_contains(preg_replace('/[^a-z0-9]/', '', strtolower($storeName)), 'libermedia');
    }

    public static function usesHerreraLayout(?string $storeName = null): bool
    {
        $theme = strtolower(trim((string) config('storefront.theme', 'auto')));

        if ($theme !== '' && $theme !== 'auto') {
            return $theme === 'herrera';
        }

        if ($storeName === null) {
            try {
                $storeName = (string) app(SystemSettingsService::class)
                    ->get('store_brand_name', config('app.name', 'AG Shop'));
            } catch (\Throwable) {
                $storeName = (string) config('app.name', 'AG Shop');
            }
        }

        return str_contains(strtolower($storeName), 'herrera');
    }
}
