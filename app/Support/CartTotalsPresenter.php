<?php

namespace App\Support;

use App\Services\Pricing\B2BAccessService;
use Illuminate\Support\Collection;

class CartTotalsPresenter
{
    /** Display amounts only; checkout and invoice snapshots remain net plus tax. */
    public static function present(array $totals, Collection $lines): array
    {
        $includesTax = config('storefront.home') === 'lavaggio'
            && ! app(B2BAccessService::class)->displaysNet(auth()->user());
        $subtotal = (float) ($totals['subtotal'] ?? 0);
        $discount = (float) ($totals['discount_total'] ?? 0);
        $shipping = (float) ($totals['shipping_total'] ?? 0);

        if ($includesTax) {
            $shippingTax = (float) ($totals['shipping_tax_total'] ?? 0);
            $subtotal = round((float) $lines->sum(fn (array $line): float => (float) $line['display_base_unit_price'] * (int) $line['quantity']), 2);
            $itemsAfterDiscount = round((float) ($totals['subtotal_after_discount'] ?? 0)
                + (float) ($totals['tax_total'] ?? 0) - $shippingTax, 2);
            $discount = round(max(0, $subtotal - $itemsAfterDiscount), 2);
            $shipping = round($shipping + $shippingTax, 2);
        }

        return [
            'includes_tax' => $includesTax,
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'shipping_total' => $shipping,
        ];
    }
}
