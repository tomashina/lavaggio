<?php

namespace App\Support\Lavaggio;

final class DeliveryArea
{
    // Unique postcodes in the shop's GLS “Posebna dostavna područja” PDF.
    private const SPECIAL_POSTCODES = [
        '20221', '20222', '20223', '20224', '20225', '20226', '20290', '21225',
        '21430', '21432', '22232', '22233', '22234', '22235', '22236', '23281',
        '23282', '23283', '23284', '23285', '23286', '23287', '23291', '23292',
        '23293', '23294', '23295', '23296', '51552', '51561', '51562',
    ];

    public static function postcode(?string $postcode): string
    {
        return preg_replace('/^HR[\s-]*/i', '', trim((string) $postcode));
    }

    public static function isSpecial(?string $country, ?string $postcode): bool
    {
        return strtoupper(trim((string) $country)) === 'HR'
            && in_array(self::postcode($postcode), self::SPECIAL_POSTCODES, true);
    }
}
