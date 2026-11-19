<?php

namespace App\Support\Lavaggio;

use App\Models\Catalog\Product\Product;
use DateTimeImmutable;

class RetailBasePrice
{
    /** Resolve dated merchant evidence independently of the current selling price. */
    public static function forProduct(Product $product): ?array
    {
        $evidence = data_get($product->payload, 'retail_price_evidence');
        if (! is_array($evidence)) {
            static $register;
            $register ??= json_decode(file_get_contents(database_path('data/lavaggio-retail-base-prices.json')), true, flags: JSON_THROW_ON_ERROR);
            $entry = $register['products'][(string) $product->sku] ?? null;
            if (! $entry || (string) $product->barcode !== $entry['barcode']) {
                return null;
            }
            $evidence = $entry + $register;
        }

        $cutoff = match ($evidence['group'] ?? '') {
            'household' => '2025-05-02',
            'other', 'service' => '2026-09-10',
            default => null,
        };
        $first = (string) ($evidence['first_offered_on'] ?? '');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $first);
        if (! $cutoff || ! $date || $date->format('Y-m-d') !== $first || trim($evidence['source'] ?? '') === '') {
            return null;
        }
        $reference = max($cutoff, $first);
        if (($evidence['price_on'] ?? null) !== $reference || ! is_bool($evidence['on_promotion'] ?? null)) {
            return null;
        }
        $field = 'retail_price';
        if ($evidence['on_promotion']) {
            $field = $evidence['group'] === 'household' ? 'lowest_30_before_promotion' : 'previous_retail_price';
        }
        $amount = $evidence[$field] ?? null;

        return is_numeric($amount) && $amount >= 0 ? ['date' => $reference, 'price' => (float) $amount] : null;
    }
}
