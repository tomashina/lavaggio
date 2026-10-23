<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\OrderItem;
use App\Services\Pricing\ProductPricePresentationService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class GiftGuideController extends Controller
{
    public function __invoke(Request $request)
    {
        if (config('storefront.home') === 'watchline') {
            return view('front.desktop.gift-guide.watchline', app(\App\Services\Front\WatchlineGiftGuideService::class)->search($request));
        }

        $filters = $request->validate([
            'available_only' => 'nullable|boolean',
            'promo_only' => 'nullable|boolean',
            'sort' => 'nullable|in:default,newest,oldest,price_low,price_high,stock_high',
            'cols' => 'nullable|integer|in:3,4,5',
            'category' => 'nullable|integer|min:1',
            'brand' => 'nullable|integer|min:1',
            'gender' => 'nullable|in:all,girl,boy',
            'age' => 'nullable|integer|in:6,12,18,24,36,48,60,72,84,96,120,144',
            'budget' => 'nullable|in:25,50,100',
            'interest' => 'nullable|in:creative,learning,building,outdoor',
        ]);
        $interests = [
            'creative' => ['kreativ', 'crtanje', 'slikanje', 'modeliranje'],
            'learning' => ['didakti', 'učenje', 'edukativ', 'slagalic', 'puzzle'],
            'building' => ['kock', 'konstruk', 'građen'],
            'outdoor' => ['lopta', 'lopte', 'vanjsk', 'tricikl', 'romobil', 'pijesak'],
        ];
        $sales = OrderItem::query()->selectRaw('COALESCE(SUM(quantity), 0)')
            ->whereColumn('order_items.product_id', 'products.id')
            ->whereHas('order', fn ($q) => $q->withoutCancelled()->whereNotNull('paid_at'));
        $query = Product::query()->where('is_active', true)
            ->whereHas('translations')->whereHas('categories.translations', fn ($q) => $q
            ->where(fn ($q) => $q->where('name', 'like', '%igra%')->orWhere('name', 'like', '%kreativ%')->orWhere('name', 'like', '%slagalic%')->orWhere('name', 'like', '%puzzle%')))
            ->with([
                'translations' => fn ($q) => $q->select(['id', 'product_id', 'locale', 'slug', 'name']),
                'taxRate', 'manufacturer.translations', 'categories.translations', 'attributes.translations',
            ])
            ->addSelect(['gift_sales' => $sales])->orderByDesc('gift_sales')->orderBy('id');
        $products = $query->get()->filter(function (Product $product) use ($filters, $interests, $request) {
            $gender = data_get($product->payload, 'gift_guide.gender', 'all');
            if (($filters['gender'] ?? 'all') !== 'all' && ! in_array($gender, ['all', $filters['gender']], true)) {
                return false;
            }
            if (! empty($filters['age'])) {
                $ages = $product->attributes->where('group_code', 'libermedia-spec-age');
                if (! $ages->contains(fn ($attribute) => $attribute->translations->contains(fn ($translation) => $this->matchesAge($translation->name, (int) $filters['age'])))) {
                    return false;
                }
            }
            if (! empty($filters['interest'])) {
                $text = mb_strtolower($product->translations->pluck('name')->join(' ').' '.$product->categories->flatMap->translations->pluck('name')->join(' '));
                if (! collect($interests[$filters['interest']])->contains(fn ($word) => str_contains($text, $word))) {
                    return false;
                }
            }
            if (! empty($filters['available_only']) && $product->stock_qty <= 0) {
                return false;
            }
            if (! empty($filters['promo_only'])) {
                $price = app(ProductPricePresentationService::class)->forProduct($product, $request->user());
                if (! $price['has_promotional_discount']) {
                    return false;
                }
            }
            if (! empty($filters['budget'])) {
                $price = app(ProductPricePresentationService::class)->forProduct($product, $request->user());
                if ((float) ($price['display_current'] ?? $price['current_gross']) > (float) $filters['budget']) {
                    return false;
                }
            }

            return true;
        })->values();
        // Derive the sidebar from the gift selection, before its own filters.
        $giftCategories = $products->flatMap->categories->unique('id')->sortBy(fn ($c) => $c->translations->first()?->name)->values();
        $giftBrands = $products->pluck('manufacturer')->filter()->unique('id')->sortBy(fn ($b) => $b->translations->first()?->name)->values();
        $products = $products->filter(fn ($p) => (empty($filters['category']) || $p->categories->contains('id', (int) $filters['category']))
            && (empty($filters['brand']) || $p->manufacturer_id === (int) $filters['brand'])
        );
        $sort = $filters['sort'] ?? 'default';
        if (in_array($sort, ['price_low', 'price_high'], true)) {
            $products = $products->sortBy(function ($p) use ($request) {
                $price = app(ProductPricePresentationService::class)->forProduct($p, $request->user());

                return (float) ($price['display_current'] ?? $price['current_gross']);
            }, SORT_REGULAR, $sort === 'price_high');
        } elseif ($sort === 'stock_high') {
            $products = $products->sortByDesc('stock_qty');
        } elseif (in_array($sort, ['newest', 'oldest'], true)) {
            $products = $products->sortBy('created_at', SORT_REGULAR, $sort === 'newest');
        }
        $products = $products->values();
        $page = max(1, (int) $request->query('page', 1));
        $pageProducts = $products->forPage($page, 60);
        $pageProducts->load(['media', 'optionValues.optionValue.translations', 'optionValues.optionValue.option']);
        $products = new LengthAwarePaginator($pageProducts, $products->count(), 60, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('front.desktop.gift-guide.index', compact('products', 'filters', 'giftCategories', 'giftBrands'));
    }

    public function matchesAge(string $label, int $months): bool
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', $label, $matches);
        if (empty($matches[0])) {
            return false;
        }
        $factor = str_contains(mb_strtolower($label), 'mjes') ? 1 : 12;
        $minimum = (float) str_replace(',', '.', $matches[0][0]) * $factor;
        if (isset($matches[0][1])) {
            $maximum = (float) str_replace(',', '.', $matches[0][1]) * $factor;
        } else {
            // A minimum safety age alone is not evidence of gift suitability
            // for every older child. These are conservative recommendation
            // windows, not manufacturer-supplied upper age limits.
            $window = $minimum < 36 ? 18 : ($minimum < 96 ? 36 : 48);
            $maximum = $minimum + $window;
        }

        return $months >= $minimum && $months <= $maximum;
    }
}
