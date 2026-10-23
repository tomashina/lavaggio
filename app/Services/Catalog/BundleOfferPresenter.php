<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\User;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\ProductPricePresentationService;

class BundleOfferPresenter
{
    public function __construct(
        private readonly ProductPricePresentationService $prices,
        private readonly BundlePricingService $bundles,
    ) {}

    /** Use the cart's bundle allocation so the displayed saving matches checkout. */
    public function present(Bundle $bundle, array $groups, ?User $user = null): array
    {
        $lines = collect();
        $canViewPrices = app(B2BAccessService::class)->canViewPrices($user);
        $available = $bundle->products->count() >= 2;
        foreach ($bundle->products as $product) {
            $quantity = (int) $product->pivot->quantity;
            $available = $available && $product->is_active
                && ! $product->hasVisibleOptionRows()
                && $product->availableStockQuantity() >= $quantity
                && $quantity >= max(1, (int) $product->minimum_order_quantity)
                && $quantity % max(1, (int) $product->order_quantity_step) === 0;
            if (! $canViewPrices) {
                continue;
            }
            $price = $this->prices->forProduct($product, $user, $quantity);
            $net = (float) $price['current_net'];
            $tax = round((float) $price['current_gross'] - $net, 2);
            $lines->push([
                'product' => $product, 'quantity' => $quantity,
                'unit_price' => $net, 'unit_tax_amount' => $tax,
                'line_total' => round($net * $quantity, 2),
                'line_tax_total' => round($tax * $quantity, 2),
                'line_discount_total' => 0, 'display_includes_tax' => true,
                'product_option_value_id' => null, 'price_is_final' => false,
            ]);
        }
        $regular = round($lines->sum('line_total') + $lines->sum('line_tax_total'), 2);
        $discounted = $this->bundles->applyBundle(clone $lines, $bundle, $groups);
        $total = round($discounted->sum('line_total') + $discounted->sum('line_tax_total'), 2);
        $saving = round(max(0, $regular - $total), 2);

        return [
            'regular_total' => $regular, 'total' => $total, 'saving' => $saving,
            'saving_percent' => $regular > 0 ? (int) round($saving / $regular * 100) : 0,
            'can_view_prices' => $canViewPrices, 'can_purchase' => $available && $canViewPrices,
            'item_quantity' => (int) $bundle->products->sum('pivot.quantity'),
        ];
    }
}
