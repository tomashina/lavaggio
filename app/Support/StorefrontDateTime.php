<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class StorefrontDateTime
{
    public static function timezone(): string
    {
        return config('storefront.home') === 'lavaggio' ? 'Europe/Zagreb' : (string) config('app.timezone');
    }

    public static function format(?CarbonInterface $date, string $format): ?string
    {
        return $date?->copy()->setTimezone(self::timezone())->format($format);
    }

    public static function fromInput(?string $value): ?string
    {
        return filled($value)
            ? Carbon::parse($value, self::timezone())->setTimezone(config('app.timezone'))->toDateTimeString()
            : null;
    }
}
