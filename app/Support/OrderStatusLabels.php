<?php

namespace App\Support;

use Illuminate\Support\Str;

class OrderStatusLabels
{
    private const CROATIAN_NAMES = [
        'new' => 'Novo',
        'pending' => 'Na čekanju',
        'processing' => 'U obradi',
        'paid' => 'Plaćeno',
        'sent' => 'Poslano',
        'shipped' => 'Poslano',
        'complete' => 'Dovršeno',
        'completed' => 'Dovršeno',
        'canceled' => 'Otkazano',
        'cancelled' => 'Otkazano',
        'denied' => 'Odbijeno',
        'canceled reversal' => 'Poništen povrat transakcije',
        'failed' => 'Neuspjelo',
        'refunded' => 'Vraćen novac',
        'reversed' => 'Stornirana transakcija',
        'chargeback' => 'Povrat po prigovoru',
        'expired' => 'Isteklo',
        'processed' => 'Obrađeno',
        'voided' => 'Poništeno',
    ];

    public static function display(string $name): string
    {
        if (! self::enabled()) {
            return $name;
        }

        return self::CROATIAN_NAMES[mb_strtolower(trim($name))] ?? $name;
    }

    public static function customerMessage(string $name, ?string $locale = null): ?string
    {
        if (config('storefront.home') !== 'lavaggio') {
            return null;
        }

        $key = match (mb_strtolower(trim($name))) {
            'pending', 'na čekanju' => 'pending',
            'processed', 'obrađeno' => 'processed',
            'sent', 'shipped', 'poslano' => 'shipped',
            default => null,
        };

        return $key ? __("mail.orders.status_messages.$key", [], $locale) : null;
    }

    /** @return array<int, string> */
    public static function matchingStoredNames(string $search): array
    {
        if (! self::enabled() || trim($search) === '') {
            return [];
        }

        $search = self::searchable($search);
        $names = [];

        foreach (self::CROATIAN_NAMES as $source => $label) {
            if (str_contains(self::searchable($label), $search)) {
                $names[] = $source;
                $names[] = mb_strtolower($label);
            }
        }

        return array_values(array_unique($names));
    }

    private static function searchable(string $value): string
    {
        return Str::ascii(mb_strtolower(trim($value)));
    }

    private static function enabled(): bool
    {
        return config('storefront.home') === 'lavaggio' && app()->getLocale() === 'hr';
    }
}
