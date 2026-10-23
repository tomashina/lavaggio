<?php

namespace App\Services\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\OrderItem;
use App\Support\ProductMaterialLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CartRecommendationService
{
    private const LIMIT = 8;

    private const CANDIDATE_LIMIT = 40;

    /** @return array{products: Collection<int, Product>, source: string} */
    public function forLines(Collection $lines, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $fallback = (string) config('app.locale');
        $cartProducts = $lines->pluck('product')->filter()->unique('id');
        $cartIds = $cartProducts->pluck('id')->all();
        if ($cartIds === []) {
            return ['products' => collect(), 'source' => 'related'];
        }

        // Count orders, rather than units, so one large order cannot dominate the suggestions.
        $anchorOrders = OrderItem::query()
            ->select('order_id')->distinct()
            ->whereIn('product_id', $cartIds)->where('quantity', '>', 0)
            ->whereHas('order', fn (Builder $orders) => $orders
                ->withoutCancelled()
                ->where(fn (Builder $paid) => $paid->whereNotNull('paid_at')
                    ->orWhereHas('status', fn (Builder $status) => $status->where('is_paid', true))));
        $coPurchases = OrderItem::query()
            ->select('product_id')->selectRaw('COUNT(DISTINCT order_id) as purchase_count')
            ->where('quantity', '>', 0)->whereNotNull('product_id')
            ->whereNotIn('product_id', $cartIds)->whereIn('order_id', $anchorOrders)
            ->groupBy('product_id');

        $products = $this->purchasable($this->products($cartIds, $locale, $fallback)
            ->joinSub($coPurchases, 'cart_co_purchases', 'cart_co_purchases.product_id', '=', 'products.id')
            ->orderByDesc('cart_co_purchases.purchase_count')->orderByDesc('products.id'));
        if ($products->isNotEmpty()) {
            return ['products' => $products, 'source' => 'co_purchase'];
        }

        $relatedIds = $cartProducts->flatMap(fn (Product $product) => $product->payload['related_product_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)->filter()->unique()->values();
        $products = $relatedIds->isEmpty() ? collect() : $this->purchasable(
            $this->products($cartIds, $locale, $fallback)->whereIn('products.id', $relatedIds)->orderByDesc('products.id')
        );

        if ($products->count() < self::LIMIT) {
            $categoryIds = $cartProducts->flatMap(fn (Product $product) => $product->categories->modelKeys())->unique();
            if ($categoryIds->isNotEmpty()) {
                $categoryProducts = $this->purchasable($this->products(
                    array_merge($cartIds, $products->pluck('id')->all()), $locale, $fallback
                )->whereHas('categories', fn (Builder $categories) => $categories
                    ->whereIn('categories.id', $categoryIds)->where('scope', Category::SCOPE_CATALOG)->currentlyVisible())
                    ->orderByDesc('products.id'));
                $products = $products->concat($categoryProducts)->take(self::LIMIT)->values();
            }
        }

        return ['products' => $products, 'source' => 'related'];
    }

    private function products(array $excludeIds, string $locale, string $fallback): Builder
    {
        $locales = array_unique([$locale, $fallback]);

        return Product::query()->select('products.*')->withStorefrontEnergyData()
            ->withApprovedCommentSummary($locales)->visibleOnStorefront(true)
            ->whereNotIn('products.id', $excludeIds)
            ->whereHas('translations', fn (Builder $translation) => $translation
                ->whereIn('locale', $locales)->whereNotNull('slug')->whereRaw("TRIM(slug) != ''"))
            ->with([
                'taxRate:id,rate,rate_type,is_active',
                'media' => fn ($query) => $query->whereIn('collection_name', ['product_main', 'product_gallery'])->orderBy('order_column')->orderBy('id'),
                'translations' => fn ($query) => $query->whereIn('locale', $locales)->whereNotNull('slug')->whereRaw("TRIM(slug) != ''"),
                'manufacturer.translations' => fn ($query) => $query->whereIn('locale', $locales),
                'categories.translations' => fn ($query) => $query->whereIn('locale', $locales),
                'attributes' => ProductMaterialLabel::eagerLoadAttributes($locale, $fallback),
                'optionValues' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('id'),
                'optionValues.optionValue.translations' => fn ($query) => $query->whereIn('locale', $locales),
                'optionValues.optionValue.option.translations' => fn ($query) => $query->whereIn('locale', $locales),
                'optionValues.parentOptionValue.translations' => fn ($query) => $query->whereIn('locale', $locales),
                'optionValues.parentOptionValue.option.translations' => fn ($query) => $query->whereIn('locale', $locales),
            ]);
    }

    private function purchasable(Builder $query): Collection
    {
        return $query->limit(self::CANDIDATE_LIMIT)->get()->filter(function (Product $product): bool {
            $minimum = max(1, (int) ($product->minimum_order_quantity ?? 1));
            if ($minimum > 999) {
                return false;
            }
            $visibleOptions = $product->visibleOptionRows();

            return $visibleOptions->isNotEmpty()
                ? $visibleOptions->contains(fn ($option): bool => (int) $option->stock_qty >= $minimum)
                : $product->availableStockQuantity() >= $minimum;
        })->take(self::LIMIT)->values();
    }
}
