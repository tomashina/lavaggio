<?php

namespace App\Services\Content;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockTranslation;
use App\Services\Catalog\B2CPromotionService;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Front\WatchlineHeroService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Support\Media\LegacyCatalogImage;
use App\Support\ProductMaterialLabel;
use App\Support\ProductNewLabel;
use App\Support\ProductPopularityLabel;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Support\Collection;

class WatchlineWidgetProducts
{
    public function forBlock(ContentBlock $block, ?ContentBlockTranslation $translation, Collection $manual): Collection
    {
        $settings = WatchlineWidgetSettings::forBlock($block, $translation);
        $limit = max(1, min(50, (int) data_get($translation?->payload, 'items_limit', 12)));
        $source = (string) ($settings['source'] ?? 'manual');
        if ($source === 'manual' && $manual->isEmpty()
            && (($settings['category_ids'] ?? []) !== [] || ($settings['manufacturer_ids'] ?? []) !== [])
            && (! $block->exists || ! $block->items()->where('item_type', 'product')->exists())) {
            $source = 'filtered';
        }
        $sort = (string) ($settings['product_sort'] ?? 'source');
        if ($sort === 'source') {
            $sort = match ($source) {
                'manual' => 'manual',
                'cluse_bestsellers' => 'bestsellers',
                'promotions' => 'largest_discount',
                default => 'newest',
            };
        }
        $promotionsOnly = (bool) ($settings['promotions_only'] ?? false) || $source === 'promotions';
        $locales = array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]));
        $categoryIds = $this->categoryIds($settings['category_ids'] ?? [], (bool) ($settings['include_children'] ?? true));
        $hideStock = (bool) ($settings['in_stock_only'] ?? true) || app(CatalogFeatureService::class)->hideOutOfStockProducts();
        $query = Product::query()->visibleOnStorefront($hideStock)
            ->whereHas('translations', fn ($query) => $query->whereIn('locale', $locales)
                ->whereNotNull('slug')->where('slug', '!=', ''));
        if ($categoryIds !== null) {
            $query->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $categoryIds));
        }
        $manufacturerIds = $settings['manufacturer_ids'] ?? [];
        if ($manufacturerIds !== []) {
            $query->whereIn('products.manufacturer_id', $manufacturerIds)
                ->whereHas('manufacturer', fn ($query) => $query->where('is_active', true));
        }
        if ($settings['popular_only'] ?? false) {
            ProductPopularityLabel::scope($query);
        }
        $manualPositions = array_flip($manual->pluck('id')->map(fn ($id) => (int) $id)->all());
        $salesPositions = [];
        if ($source === 'manual') {
            $query->whereIn('products.id', array_keys($manualPositions));
        } elseif ($source === 'new_products') {
            ProductNewLabel::scope($query);
        } elseif ($source === 'guess_newest') {
            $query->whereHas('manufacturer', fn ($query) => $query->where('is_active', true)
                ->whereHas('translations', fn ($query) => $query->where('locale', 'hr')
                    ->where(fn ($query) => $query->where('slug', 'guess-satovi')->orWhereRaw('LOWER(name) = ?', ['guess']))));
        } elseif ($source === 'cluse_bestsellers') {
            $rankedIds = app(WatchlineHeroService::class)->rankedProductIds((clone $query)->pluck('products.id'));
            $salesPositions = array_flip($rankedIds->all());
            $query->whereIn('products.id', $rankedIds);
        }
        if ($promotionsOnly && config('commerce.b2c_only')) {
            app(B2CPromotionService::class)->filter($query, auth()->user());
        }

        // Filter the full candidate set before limiting; keep only the best IDs in memory.
        $selected = [];
        $prices = app(ProductPricePresentationService::class);
        $needsPrice = $promotionsOnly || in_array($sort, ['largest_discount', 'price_asc', 'price_desc'], true);
        $dateColumn = $query->getQuery()->getGrammar()->wrap('products.payload->opencart->date_added');
        $query->with(['media', 'taxRate', 'translations', 'categories'])
            ->orderByRaw("COALESCE(NULLIF({$dateColumn}, ''), products.created_at) DESC")
            ->orderByDesc('products.id')
            ->chunk(200, function (Collection $batch) use (&$selected, $prices, $needsPrice, $promotionsOnly, $limit, $locales, $sort, $manualPositions, $salesPositions, $categoryIds): bool {
                foreach ($batch as $product) {
                    $translation = $product->translations->firstWhere('locale', $locales[0])
                        ?? $product->translations->firstWhere('locale', $locales[1] ?? $locales[0]);
                    if (! filled($translation?->slug) || LegacyCatalogImage::first($product) === null) {
                        continue;
                    }
                    $price = $needsPrice ? $prices->forProduct($product, auth()->user()) : [];
                    if ($promotionsOnly && ! ($price['has_promotional_discount'] ?? false)) {
                        continue;
                    }
                    $selected[] = ['id' => (int) $product->id, 'key' => $this->sortKey($product, $sort, $price, $manualPositions, $salesPositions, $categoryIds)];
                    if ($sort === 'newest' && count($selected) >= $limit) {
                        return false;
                    }
                }
                usort($selected, fn ($first, $second) => $first['key'] <=> $second['key']);
                $selected = array_slice($selected, 0, $limit);

                return true;
            });
        usort($selected, fn ($first, $second) => $first['key'] <=> $second['key']);
        $ids = collect(array_slice($selected, 0, $limit))->pluck('id');
        if ($ids->isEmpty()) {
            return collect();
        }
        $products = Product::query()->whereIn('id', $ids)
            ->withStorefrontEnergyData()->withApprovedCommentSummary($locales)
            ->with([
                'media', 'taxRate', 'translations', 'manufacturer.translations', 'categories.translations',
                'attributes' => ProductMaterialLabel::eagerLoadAttributes($locales[0], $locales[1] ?? $locales[0]),
            ])->get()->keyBy('id');

        return $ids->map(fn ($id) => $products->get($id))->filter()->values();
    }

    /** Null means unrestricted; an empty collection means no eligible selected categories. */
    private function categoryIds(array $selected, bool $includeChildren): ?Collection
    {
        if ($selected === []) {
            return null;
        }
        $roots = Category::query()->where('scope', Category::SCOPE_CATALOG)->currentlyVisible()->whereIn('id', $selected)->get();
        if (! $includeChildren) {
            return $roots->pluck('id');
        }

        return $roots->flatMap(fn (Category $category) => Category::query()->whereDescendantOrSelf($category)
            ->where('scope', Category::SCOPE_CATALOG)->currentlyVisible()->pluck('id'))->unique()->values();
    }

    private function sortKey(Product $product, string $sort, array $price, array $manualPositions, array $salesPositions, ?Collection $categoryIds): array
    {
        $date = data_get($product->payload, 'opencart.date_added') ?: $product->created_at?->format('Y-m-d H:i:s');
        $timestamp = strtotime((string) $date) ?: 0;
        $current = $price['current_gross'] ?? null;
        $old = $price['old_gross'] ?? null;
        $discount = ($price['has_promotional_discount'] ?? false) && $old > 0 && $current !== null
            ? round(max(0, ($old - $current) / $old), 10) : 0;
        $categoryOrder = $sort === 'category_order' ? $product->categories
            ->when($categoryIds !== null, fn (Collection $categories) => $categories->whereIn('id', $categoryIds))
            ->min(fn ($category) => (int) ($category->pivot?->sort_order ?? PHP_INT_MAX)) : null;

        return match ($sort) {
            'manual' => [$manualPositions[$product->id] ?? PHP_INT_MAX],
            'bestsellers' => [$salesPositions[$product->id] ?? PHP_INT_MAX],
            'largest_discount' => [-$discount, -$timestamp, -(int) $product->id],
            'price_asc' => [$current ?? PHP_FLOAT_MAX, -$timestamp, -(int) $product->id],
            'price_desc' => [$current === null ? PHP_FLOAT_MAX : -$current, -$timestamp, -(int) $product->id],
            'category_order' => [$categoryOrder ?? PHP_INT_MAX, -$timestamp, -(int) $product->id],
            default => [-$timestamp, -(int) $product->id],
        };
    }
}
