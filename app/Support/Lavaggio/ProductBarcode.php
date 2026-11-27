<?php

namespace App\Support\Lavaggio;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;

final class ProductBarcode
{
    public static function ean(Product $product, ?ProductOptionValue $variant = null): string
    {
        $ean = self::number($product, $variant);

        return preg_match('/^(?:\d{8}|\d{13})$/D', $ean) ? self::checked($ean) : '';
    }

    public static function gtin(Product $product, ?ProductOptionValue $variant = null): string
    {
        $gtin = str_replace([' ', '-'], '', self::number($product, $variant));
        if (! preg_match('/^(?:\d{8}|\d{12,14})$/D', $gtin) || trim($gtin, '0') === '') {
            return '';
        }
        $prefix = match (strlen($gtin)) {
            14 => substr($gtin, 1),
            12 => '0'.$gtin,
            default => $gtin,
        };

        return preg_match('/^(?:02|04|2|98|99)/', $prefix) ? '' : self::checked($gtin);
    }

    private static function number(Product $product, ?ProductOptionValue $variant): string
    {
        $payload = $variant ? $variant->payload : $product->payload;
        $barcode = $variant ? data_get($payload, 'barcode') : $product->barcode;
        $ean = trim((string) data_get($payload, 'ean'));

        return $ean !== '' ? $ean : trim((string) $barcode);
    }

    private static function checked(string $ean): string
    {
        $sum = 0;
        foreach (str_split(strrev(substr($ean, 0, -1))) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($ean, -1) ? $ean : '';
    }
}
