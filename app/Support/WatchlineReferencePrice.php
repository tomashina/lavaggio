<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use Illuminate\Support\Carbon;

class WatchlineReferencePrice
{
    public static function text(Product $product): ?string
    {
        if (config('storefront.home') !== 'watchline') {
            return null;
        }
        $reference = data_get($product->payload, 'watchline_anchor_price');
        if (! is_array($reference) || ($reference['verification_status'] ?? '') !== 'confirmed'
            || ($reference['currency_code'] ?? '') !== 'EUR' || ! is_numeric($reference['gross_price'] ?? null)
            || (float) $reference['gross_price'] < 0 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($reference['reference_date'] ?? ''))) {
            return null;
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $reference['reference_date']);
            if ($date->format('Y-m-d') !== $reference['reference_date']) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return __('Cijena na :date: :price', [
            'date' => $date->format('j. n. Y.'),
            'price' => number_format((float) $reference['gross_price'], 2, ',', '.').' €',
        ]);
    }
}
