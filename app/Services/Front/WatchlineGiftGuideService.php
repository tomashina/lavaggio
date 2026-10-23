<?php

namespace App\Services\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Services\Catalog\B2CPromotionService;
use App\Services\Catalog\CatalogFeatureService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use App\Support\ProductMaterialLabel;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WatchlineGiftGuideService
{
    public const RECIPIENTS = ['all' => 'Za sve', 'for_her' => 'Za nju', 'for_him' => 'Za njega', 'children' => 'Za djecu'];

    public const TYPES = ['all' => 'Svi artikli', 'watches' => 'Satovi', 'sunglasses' => 'Sunčane naočale', 'jewelry' => 'Nakit'];

    public const BUDGETS = ['all' => 'Bez ograničenja', '50' => 'Do 50 €', '100' => 'Do 100 €', '200' => 'Do 200 €', '300' => 'Do 300 €', '300_plus' => 'Više od 300 €'];

    private const TAXONOMY = [
        63 => 'zenski-satovi', 64 => 'muski-satovi', 65 => 'djecji-satovi',
        66 => 'satovi', 67 => 'naocale', 68 => 'muske-naocale', 69 => 'zenske-naocale', 70 => 'nakit',
    ];

    public function __construct(private readonly ProductPricePresentationService $prices, private readonly SystemSettingsService $settings) {}

    public function search(Request $request): array
    {
        $validated = $request->validate([
            'recipient' => ['nullable', 'in:'.implode(',', array_keys(self::RECIPIENTS))],
            'type' => ['nullable', 'in:'.implode(',', array_keys(self::TYPES))],
            'budget' => ['nullable', 'in:'.implode(',', array_keys(self::BUDGETS))],
            'brand' => ['nullable', 'integer', 'min:1'],
            'available_only' => ['nullable', 'boolean'], 'promo_only' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:default,newest,oldest,price_low,price_high,stock_high'],
            'cols' => ['nullable', 'integer', 'in:3,4,5'], 'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters = [
            'recipient' => $validated['recipient'] ?? 'all', 'type' => $validated['type'] ?? 'all',
            'budget' => $validated['budget'] ?? 'all', 'brand' => isset($validated['brand']) ? (int) $validated['brand'] : null,
            'available_only' => (bool) ($validated['available_only'] ?? false),
            'promo_only' => (bool) ($validated['promo_only'] ?? false),
            'sort' => $validated['sort'] ?? 'default', 'cols' => (int) ($validated['cols'] ?? 5),
        ];
        $locales = array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]));
        $categories = Category::query()->where('scope', Category::SCOPE_CATALOG)->currentlyVisible()->with('translations')->get();
        $typeRoots = match ($filters['type']) {
            'watches' => [66], 'sunglasses' => [67], 'jewelry' => [70], default => [66, 67, 70],
        };
        $typeIds = $this->categoryIds($categories, $typeRoots);
        $recipientRoots = match ($filters['recipient']) {
            'for_her' => [63, 69], 'for_him' => [64, 68], 'children' => [65], default => null,
        };
        $recipientIds = $recipientRoots === null ? null : $this->categoryIds($categories, $recipientRoots);
        $sales = DB::table('order_items as gift_items')
            ->join('orders as gift_orders', 'gift_orders.id', '=', 'gift_items.order_id')
            ->join('order_statuses as gift_statuses', 'gift_statuses.id', '=', 'gift_orders.status_id')
            ->whereColumn('gift_items.product_id', 'products.id')->where('gift_items.quantity', '>', 0)
            ->where('gift_statuses.is_cancelled', false)
            ->where(fn ($query) => $query->whereIn('gift_statuses.code', ['herrera-oc-status-5', 'watchline-oc-status-5'])->orWhere('gift_statuses.is_paid', true))
            ->selectRaw('COALESCE(SUM(gift_items.quantity), 0)');
        $query = Product::query()->select('products.*')->addSelect(['gift_sales' => $sales])
            ->visibleOnStorefront(app(CatalogFeatureService::class)->hideOutOfStockProducts())
            ->availableWithin48Hours($filters['available_only'])
            ->whereHas('translations', fn ($query) => $query->whereIn('locale', $locales)->whereNotNull('slug')->where('slug', '!=', ''))
            ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $typeIds))
            ->when($recipientIds !== null, fn ($query) => $query->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $recipientIds)))
            ->with([
                'translations' => fn ($query) => $query->select(['id', 'product_id', 'locale', 'slug', 'name']),
                'taxRate', 'manufacturer.translations', 'categories',
            ])->orderByDesc('gift_sales')->orderBy('products.id');
        if ($filters['promo_only'] && config('commerce.b2c_only')) {
            app(B2CPromotionService::class)->filter($query, $request->user());
        }
        $priceValues = [];
        $needsPrice = $filters['budget'] !== 'all' || $filters['promo_only'] || in_array($filters['sort'], ['price_low', 'price_high'], true);
        $defaultTaxRate = TaxRate::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->first();
        $candidates = $query->get();
        $this->loadDefaultTaxRate($candidates, $defaultTaxRate);
        $eligible = $candidates->filter(function (Product $product) use ($request, $filters, $needsPrice, &$priceValues): bool {
            if (! $needsPrice) {
                return true;
            }
            $price = $this->prices->forProduct($product, $request->user());
            $current = $price['display_current'];
            $priceValues[$product->id] = $current;
            if ($filters['promo_only'] && ! $price['has_promotional_discount']) {
                return false;
            }
            if ($filters['budget'] !== 'all') {
                if ($current === null) {
                    return false;
                }
                if ($filters['budget'] === '300_plus') {
                    return $current > 300.00001;
                }

                return $current <= (float) $filters['budget'] + 0.00001;
            }

            return true;
        });
        // The brand's own selection must not erase the other matching brand choices.
        $giftBrands = $eligible->pluck('manufacturer')->filter(fn ($brand) => $brand?->is_active)
            ->unique('id')->sortBy(fn ($brand) => mb_strtolower((string) ($brand->translations->firstWhere('locale', $locales[0])
                ?? $brand->translations->firstWhere('locale', $locales[1] ?? $locales[0]))?->name))->values();
        $selected = $eligible->when($filters['brand'] !== null, fn (Collection $products) => $products->where('manufacturer_id', $filters['brand']));
        if ($filters['sort'] !== 'default') {
            $selected = $selected->sort(function (Product $first, Product $second) use ($filters, $priceValues): int {
                $key = function (Product $product) use ($filters, $priceValues): array {
                    $date = data_get($product->payload, 'opencart.date_added') ?: $product->created_at?->format('Y-m-d H:i:s');
                    $timestamp = strtotime((string) $date) ?: 0;

                    return match ($filters['sort']) {
                        'price_low' => [$priceValues[$product->id] ?? PHP_FLOAT_MAX, $product->id],
                        'price_high' => [isset($priceValues[$product->id]) ? -$priceValues[$product->id] : PHP_FLOAT_MAX, $product->id],
                        'stock_high' => [-(int) $product->stock_qty, $product->id],
                        'oldest' => [$timestamp, $product->id],
                        default => [-$timestamp, $product->id],
                    };
                };

                return $key($first) <=> $key($second);
            });
        }
        $selected = $selected->values();
        $perPage = $this->settings->getInt('front_category_products_per_page_desktop', 24, 1, 60);
        $page = max(1, (int) ($validated['page'] ?? 1));
        $pageProducts = $selected->forPage($page, $perPage)->values();
        if ($pageProducts->isNotEmpty()) {
            $cardData = Product::query()->whereIn('id', $pageProducts->modelKeys())
                ->withStorefrontEnergyData()->withApprovedCommentSummary($locales)
                ->with(['media', 'translations', 'taxRate', 'manufacturer.translations', 'categories.translations',
                    'optionValues.optionValue.translations', 'optionValues.optionValue.option',
                    'attributes' => ProductMaterialLabel::eagerLoadAttributes($locales[0], $locales[1] ?? $locales[0]),
                ])->get()->keyBy('id');
            $this->loadDefaultTaxRate($cardData, $defaultTaxRate);
            $pageProducts = $pageProducts->map(fn (Product $product) => $cardData->get($product->id));
        }
        $products = new LengthAwarePaginator($pageProducts, $selected->count(), $perPage, $page, ['path' => $request->url(), 'query' => $filters, 'fragment' => 'preporuke']);

        return [
            'products' => $products, 'filters' => $filters, 'giftBrands' => $giftBrands,
            'recipientOptions' => self::RECIPIENTS, 'typeOptions' => self::TYPES, 'budgetOptions' => self::BUDGETS,
            'locale' => $locales[0], 'fallbackLocale' => $locales[1] ?? $locales[0],
        ];
    }

    private function categoryIds(Collection $categories, array $sourceIds): Collection
    {
        $roots = $categories->filter(fn (Category $category) => collect($sourceIds)->contains(fn (int $sourceId) => in_array($category->code, ['herrera-oc-category-'.$sourceId, 'watchline-oc-category-'.$sourceId], true)
            || (int) data_get($category->payload, 'opencart.category_id', 0) === $sourceId
            || $category->translations->contains(fn ($translation) => $translation->locale === 'hr' && $translation->slug === self::TAXONOMY[$sourceId])));

        return $categories->filter(fn (Category $category) => $roots->contains(fn (Category $root) => $category->_lft >= $root->_lft && $category->_rgt <= $root->_rgt))->pluck('id');
    }

    private function loadDefaultTaxRate(Collection $products, ?TaxRate $default): void
    {
        if ($default === null) {
            return;
        }
        // TaxPricingService uses this exact default for products without an active
        // assigned rate. Load it once for catalogue-wide budget/price comparisons.
        foreach ($products as $product) {
            if (! $product->taxRate?->is_active) {
                $product->setRelation('taxRate', $default);
            }
        }
    }
}
