<?php

namespace App\Support\Lavaggio;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;

final class ProductBarcode
{
    public static function ean(Product $product, ?ProductOptionValue $variant = null): string
    {
        $payload = $variant ? $variant->payload : $product->payload;
        $barcode = $variant ? data_get($payload, 'barcode') : $product->barcode;
        $ean = trim((string) data_get($payload, 'ean'));
        if ($ean === '') {
            $ean = trim((string) $barcode);
        }
        if (! preg_match('/^(?:\d{8}|\d{13})$/D', $ean)) {
            return '';
        }
        $sum = 0;
        foreach (str_split(strrev(substr($ean, 0, -1))) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) substr($ean, -1) ? $ean : '';
    }
}
