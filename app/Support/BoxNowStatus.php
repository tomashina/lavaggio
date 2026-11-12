<?php

namespace App\Support;

class BoxNowStatus
{
    public static function normalize(string $state): string
    {
        return match (strtolower(trim($state))) {
            'in-final-destination' => 'final-destination',
            'expired-return' => 'expired',
            'cancelled' => 'canceled',
            default => strtolower(trim($state)),
        };
    }

    public static function label(?string $state): string
    {
        return match (self::normalize($state ?? '')) {
            'new' => 'Čeka preuzimanje od trgovine',
            'in-transit', 'accepted-to-locker', 'wait-for-load' => 'Paket je na putu',
            'in-depot' => 'Paket je u BOX NOW skladištu',
            'final-destination' => 'Paket je u paketomatu — spreman za preuzimanje',
            'delivered' => 'Paket je preuzet',
            'expired' => 'Istekao je rok preuzimanja — paket se vraća',
            'accepted-for-return' => 'Povrat pošiljke je u tijeku',
            'returned' => 'Paket je vraćen pošiljatelju',
            'canceled' => 'Dostava je otkazana',
            'lost', 'missing' => 'Poteškoća u dostavi — paket se traži',
            default => 'Status dostave se provjerava',
        };
    }

    public static function orderStatus(string $state): ?string
    {
        return match (self::normalize($state)) {
            'new' => 'boxnow_waiting',
            'in-transit', 'in-depot', 'accepted-to-locker', 'wait-for-load' => 'boxnow_sent',
            'final-destination' => 'boxnow_ready',
            'delivered' => 'boxnow_delivered',
            'expired', 'accepted-for-return' => 'boxnow_returning',
            'returned' => 'boxnow_returned',
            'canceled' => 'boxnow_cancelled',
            'lost', 'missing' => 'boxnow_issue',
            default => null,
        };
    }

    public static function orderLabel(string $state): string
    {
        return match (self::orderStatus($state)) {
            'boxnow_waiting' => 'Pripremljeno za dostavu',
            'boxnow_sent' => 'Poslano',
            'boxnow_ready' => 'Spremno za preuzimanje',
            'boxnow_delivered' => 'Dostavljeno',
            'boxnow_returning' => 'Povrat pošiljke u tijeku',
            'boxnow_returned' => 'Pošiljka vraćena',
            'boxnow_cancelled' => 'Dostava otkazana',
            default => 'Poteškoća u dostavi',
        };
    }
}
