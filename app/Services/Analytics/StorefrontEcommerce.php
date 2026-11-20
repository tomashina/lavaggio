<?php

namespace App\Services\Analytics;

use App\Models\Sales\Order\Order;
use App\Services\Front\StoreSettingsService;
use App\Services\Payments\CorvusPayFormService;
use App\Services\Payments\KeksPayService;
use App\Services\Payments\WSPayFormService;
use App\Support\OrderStatusClassification;
use Illuminate\Support\Collection;

class StorefrontEcommerce
{
    public static function enabled(): bool
    {
        if (! app()->environment('production') || config('commerce.local_safe_mode')) {
            return false;
        }

        $settings = app(StoreSettingsService::class)->analytics();

        return ($settings['enabled'] && $settings['ga4_measurement_id'] !== '') || $settings['meta_pixel_id'] !== '';
    }

    public static function cart(iterable $lines, array $summary = []): array
    {
        $items = [];
        $eligibleKeys = $summary['cart_discount_eligible_keys'] ?? null;
        $eligible = [];
        foreach ($lines as $line) {
            $items[] = self::cartItem($line);
            $eligible[] = $eligibleKeys === null || in_array($line['key'], $eligibleKeys, true);
        }

        return self::merchandise($items, $eligible, (float) ($summary['cart_discount_total'] ?? 0), 'EUR', $summary['coupon_code'] ?? '');
    }

    /** A cart action measures the quantity the shop actually added or removed. */
    public static function cartChange(Collection $before, Collection $after): ?array
    {
        $before = $before->keyBy('key');
        $after = $after->keyBy('key');
        $items = [];
        $event = null;
        foreach ($before->keys()->merge($after->keys())->unique() as $key) {
            $delta = ($after[$key]['quantity'] ?? 0) - ($before[$key]['quantity'] ?? 0);
            if ($delta === 0) {
                continue;
            }
            $event = $delta > 0 ? 'add_to_cart' : 'remove_from_cart';
            $item = self::cartItem($delta > 0 ? $after[$key] : $before[$key]);
            $item['quantity'] = abs($delta);
            $items[] = $item;
        }

        return $event ? ['name' => $event, 'params' => self::merchandise($items)] : null;
    }

    public static function purchase(Order $order): ?array
    {
        $status = $order->status;
        $cancelledCodes = [...OrderStatusClassification::CANCELLED_NAMES, 'refunded', 'failed', 'declined'];
        if ($status?->is_cancelled || in_array(strtolower(trim((string) $status?->code)), $cancelledCodes, true)
            || in_array(strtolower(trim((string) $status?->getRawOriginal('name'))), $cancelledCodes, true)
            || $order->items->isEmpty()) {
            return null;
        }

        $corvus = app(CorvusPayFormService::class);
        $onlinePayment = $corvus->isCorvusOrder($order) || app(WSPayFormService::class)->isWspayOrder($order)
            || app(KeksPayService::class)->isKeksOrder($order);
        if ($onlinePayment && ! $order->paid_at && ! $corvus->hasPreauthorization($order)) {
            return null;
        }

        $items = [];
        $eligible = [];
        foreach ($order->items as $line) {
            $quantity = (int) $line->quantity;
            if ($quantity < 1) {
                continue;
            }
            $price = round((float) $line->line_total / $quantity, 8);
            $items[] = [
                'item_id' => (string) ($line->sku ?: $line->code ?: $line->product_id ?: $line->id),
                'item_name' => (string) $line->name,
                'price' => $price,
                'quantity' => $quantity,
                'discount' => round(max(0, (float) $line->unit_price - $price), 8),
            ];
            $eligible[] = (bool) data_get($line->payload, 'pricing.cart_discount_eligible', true);
        }
        if ($items === []) {
            return null;
        }

        $cartDiscount = max(0, (float) $order->discount_total - (float) $order->items->sum('discount_amount'));

        return self::merchandise($items, $eligible, $cartDiscount, $order->currency_code ?: 'EUR', (string) data_get($order->payload, 'coupon_code')) + [
            'transaction_id' => (string) $order->order_number,
            'tax' => (float) $order->tax_total,
            'shipping' => (float) $order->shipping_total,
        ];
    }

    private static function cartItem(array $line): array
    {
        $product = $line['product'];
        $item = [
            'item_id' => (string) (($line['sku'] ?? null) ?: $product->sku ?: $product->id),
            'item_name' => (string) ($line['translation']?->name ?? $product->code),
            'price' => (float) $line['unit_price'],
            'quantity' => (int) $line['quantity'],
            'discount' => (float) ($line['unit_discount'] ?? 0),
        ];
        if (! empty($line['option_label'])) {
            $item['item_variant'] = $line['option_label'];
        }
        if ($brand = data_get($product, 'manufacturer.translations')?->firstWhere('locale', app()->getLocale())?->name) {
            $item['item_brand'] = $brand;
        }

        return $item;
    }

    private static function merchandise(array $items, array $eligible = [], float $cartDiscount = 0, string $currency = 'EUR', string $coupon = ''): array
    {
        $amounts = array_map(static fn (array $item): float => $item['price'] * $item['quantity'], $items);
        if ($cartDiscount > 0) {
            $base = array_sum(array_filter($amounts, static fn (int $i): bool => $eligible[$i], ARRAY_FILTER_USE_KEY));
            $cartDiscount = min($base, $cartDiscount);
            $runningBase = $allocated = 0.0;
            foreach ($items as $i => $item) {
                if (! $eligible[$i] || $amounts[$i] <= 0) {
                    continue;
                }
                // Cumulative rounding assigns the last cent to an eligible line.
                $runningBase += $amounts[$i];
                $nextAllocated = round($cartDiscount * $runningBase / $base, 2);
                $share = $nextAllocated - $allocated;
                $allocated = $nextAllocated;
                $amounts[$i] -= $share;
                $items[$i]['price'] = round($amounts[$i] / $item['quantity'], 8);
                $items[$i]['discount'] = round($item['discount'] + $share / $item['quantity'], 8);
            }
        }

        $payload = ['currency' => $currency, 'value' => round(array_sum($amounts), 2), 'items' => $items];
        if ($coupon !== '') {
            $payload['coupon'] = $coupon;
        }

        return $payload;
    }
}
