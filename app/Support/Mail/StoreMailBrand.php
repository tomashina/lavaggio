<?php

namespace App\Support\Mail;

use App\Services\Settings\SystemSettingsService;

final class StoreMailBrand
{
    public static function usesSharedDesign(): bool
    {
        return in_array(config('storefront.home'), ['watchline', 'lavaggio'], true);
    }

    public static function isLavaggio(): bool
    {
        return config('storefront.home') === 'lavaggio';
    }

    public static function name(): string
    {
        return self::isLavaggio() ? 'Lavaggio' : 'Watchline';
    }

    /** @return array<string, string|int> */
    public static function details(): array
    {
        if (! self::isLavaggio()) {
            return [
                'name' => 'Watchline', 'tagline' => 'Satovi, naočale i nakit.',
                'logo' => asset('assets/brand/watchline-mail-logo.png'), 'width' => 160, 'height' => 78,
                'accent' => '#0077b5', 'link' => '#0077b5', 'ink' => '#111111',
                'paper' => '#f4f8fb', 'panel' => '#edf6fc', 'line' => '#dce8ef',
                'muted' => '#52616c', 'email' => '', 'phone' => '', 'address' => '',
            ];
        }

        // Use the same configured contact information as the storefront. A
        // missing setting stays empty instead of borrowing another shop's data.
        try {
            $settings = app(SystemSettingsService::class)->all();
        } catch (\Throwable) {
            $settings = [];
        }
        $email = trim((string) ($settings['store_footer_email_support'] ?? $settings['store_footer_email_sales'] ?? ''));

        return [
            'name' => 'Lavaggio', 'tagline' => 'Sve za čisto, svježe i njegovano rublje.',
            'logo' => asset('assets/lavaggio/images/logo/lavaggio-mail-logo.png'), 'width' => 220, 'height' => 50,
            'accent' => '#848ac4', 'link' => '#656ba8', 'ink' => '#13100d',
            'paper' => '#f5f5fa', 'panel' => '#f1f2f9', 'line' => '#e4e5f0', 'muted' => '#6b6772',
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'phone' => trim((string) ($settings['store_footer_phone'] ?? '')),
            'address' => trim((string) ($settings['store_footer_address'] ?? '')),
        ];
    }
}
