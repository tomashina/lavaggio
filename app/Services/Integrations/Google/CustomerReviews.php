<?php

namespace App\Services\Integrations\Google;

use App\Models\Sales\Order\Order;
use App\Services\Analytics\StorefrontEcommerce;
use App\Services\Settings\SystemSettingsService;
use App\Support\Lavaggio\ProductBarcode;
use App\Support\StorefrontDateTime;

class CustomerReviews
{
    public function payload(Order $order): ?array
    {
        $settings = app(SystemSettingsService::class);
        if (config('storefront.home') !== 'lavaggio' || ! app()->environment('production') || config('commerce.local_safe_mode')
            || ! $settings->get('lavaggio_merchant_reviews_enabled', false) || ! StorefrontEcommerce::isConfirmedOrder($order)) {
            return null;
        }
        $merchant = trim((string) $settings->get('lavaggio_merchant_id', ''));
        $country = strtoupper(trim((string) ($order->shipping_country_code ?: $order->billing_country_code)));
        $placed = ($order->placed_at ?? $order->created_at)?->copy()->setTimezone(StorefrontDateTime::timezone())->startOfDay();
        if (! $placed || ! preg_match('/^[1-9][0-9]{0,14}$/D', $merchant)
            || ! preg_match('/^[A-Z]{2}$/D', $country) || $country === 'ZZ' || ! filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $delivery = $placed->copy()->addWeekdays(3);
        $gtins = [];
        $legacyItems = $order->items->filter(fn ($item) => ! array_key_exists('gtin', $item->payload ?? []));
        $legacyItems->loadMissing(['product', 'productOptionValue']);
        foreach ($order->items as $item) {
            $snapshot = $item->payload ?? [];
            $mode = array_key_exists('delivery_mode', $snapshot) ? $snapshot['delivery_mode'] : $item->product?->delivery_mode;
            $estimate = match (true) {
                $mode === 'order' => $placed->copy()->addDays(20),
                $mode === 'supplier' || data_get($snapshot, 'inventory.supplier_quantity', 0) > 0 => $placed->copy()->addWeekdays(10),
                default => $delivery,
            };
            $delivery = $delivery->max($estimate);
            $gtin = $snapshot['gtin'] ?? ($item->product ? ProductBarcode::gtin($item->product, $item->productOptionValue) : '');
            if ($gtin !== '') {
                $gtins[$gtin] = ['gtin' => $gtin];
            }
        }

        $data = [
            'merchant_id' => (int) $merchant,
            'order_id' => (string) $order->order_number,
            'email' => $order->customer_email,
            'delivery_country' => $country,
            'estimated_delivery_date' => $delivery->toDateString(),
        ];
        if ($gtins) {
            $data['products'] = array_values($gtins);
        }

        return $data;
    }
}
