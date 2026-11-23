<?php

namespace App\Support\Lavaggio;

use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderItem;

class OrderPrices
{
    /** Customer-facing amounts from the order, including its original discounts and shipping VAT. */
    public static function totals(Order $order): array
    {
        $shippingTax = data_get($order->payload, 'shipping.tax_total');
        if ($shippingTax === null && (float) $order->shipping_total > 0) {
            $shippingTax = max(0, (float) $order->tax_total - $order->items->sum('tax_amount'));
        }
        $itemsTax = (float) $order->tax_total - (float) $shippingTax;
        $subtotal = (float) $order->subtotal + $itemsTax;
        $afterDiscount = $subtotal - (float) $order->discount_total;
        if ($order->items->isNotEmpty()) {
            $subtotal = $order->items->sum(fn (OrderItem $item) => $item->grossBaseTotal());
            $cartDiscount = max(0, (float) $order->discount_total - $order->items->sum('discount_amount'));
            $afterDiscount = $order->items->sum('line_total') + $itemsTax - $cartDiscount;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round(max(0, $subtotal - $afterDiscount), 2),
            'shipping' => round((float) $order->shipping_total + (float) $shippingTax, 2),
            'payment_fee' => (float) $order->payment_fee_total,
            'tax' => (float) $order->tax_total,
            'gift_voucher' => (float) data_get($order->payload, 'gift_voucher_total', 0),
            'grand_total' => (float) $order->grand_total,
        ];
    }

    public static function displayRows(Order $order): array
    {
        if (config('storefront.home') !== 'lavaggio') {
            return [];
        }
        $totals = self::totals($order);

        $subtotal = ['label' => __('ui.cart.summary.subtotal_with_tax'), 'value' => $totals['subtotal']];
        $tax = ['label' => __('mail.orders.includes_vat')];
        if ($order->totals->where('code', 'tax')->count() <= 1) {
            $tax['value'] = $totals['tax'];
        }

        return [
            'subtotal' => $subtotal,
            'sub_total' => $subtotal,
            'discount' => ['label' => __('mail.orders.discount'), 'value' => -$totals['discount']],
            'shipping' => ['label' => __('ui.cart.summary.shipping_with_tax'), 'value' => $totals['shipping']],
            'tax' => $tax,
        ];
    }
}
