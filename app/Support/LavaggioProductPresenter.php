<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;

class LavaggioProductPresenter
{
    public function money(float $value): string
    {
        return number_format($value, 2, ',', '.').' €';
    }

    public function contentLabel(Product $product, float $grossPrice): ?string
    {
        $quantity = (float) $product->content_quantity;
        $unit = ['l' => 'l', 'kg' => 'kg', 'pcs' => 'kom'][$product->content_unit] ?? null;

        if ($quantity <= 0 || $unit === null) {
            return null;
        }

        $quantityLabel = rtrim(rtrim(number_format($quantity, 3, ',', '.'), '0'), ',');

        return $quantityLabel.' '.$unit.' ('.$this->money($grossPrice / $quantity).' za 1 '.$unit.')';
    }

    /** @return array{label:string,state:string} */
    public function stock(Product $product): array
    {
        $quantity = in_array($product->delivery_mode, ['supplier', 'order'], true)
            ? $product->availableStockQuantity()
            : max(0, (int) $product->stock_qty);
        return [
            'label' => $quantity === 0 ? 'NEDOSTUPNO' : ($quantity < 3 ? 'ZADNJI KOMADI' : 'NA STANJU'),
            'state' => $quantity === 0 ? 'unavailable' : ($quantity < 3 ? 'low' : 'available'),
        ];
    }

    public function delivery(Product $product): string
    {
        return match ($product->delivery_mode) {
            'supplier' => 'Dostava u 4–10 radnih dana',
            'order' => 'Dostava od 10 do 20 dana',
            'unavailable' => 'Trenutno nedostupno',
            default => (int) $product->stock_qty > 0 ? 'Dostava u 2–3 radna dana' : 'Trenutno nedostupno',
        };
    }

    public function freeShippingThreshold(): float
    {
        return max(0, (float) app(SystemSettingsService::class)->get('lavaggio_free_shipping_threshold', 50));
    }
}
