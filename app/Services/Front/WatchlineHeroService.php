<?php

namespace App\Services\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Models\User;
use App\Services\Pricing\ProductPricePresentationService;
use App\Support\Media\LegacyCatalogImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WatchlineHeroService
{
    public function __construct(private readonly ProductPricePresentationService $prices) {}

    /** Rank eligible women's CLUSE watches before applying a widget limit. */
    public function rankedProductIds(?Collection $allowedIds = null): Collection
    {
        $root = Category::query()->where('scope', Category::SCOPE_CATALOG)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('code', 'herrera-oc-category-63')
                ->orWhereHas('translations', fn (Builder $translation) => $translation->where('locale', 'hr')->where('slug', 'zenski-satovi')))
            ->first();
        if (! $root) {
            return collect();
        }
        $categoryIds = Category::query()->where('scope', Category::SCOPE_CATALOG)
            ->where('is_active', true)->whereBetween('_lft', [$root->_lft, $root->_rgt])->pluck('id');
        $reviews = DB::table('content_comments')->where('commentable_type', (new Product)->getMorphClass())
            ->where('status', Comment::STATUS_APPROVED)->whereNull('deleted_at')->whereNull('parent_id')
            ->whereBetween('rating', [1, 5])->where('locale', 'hr')
            ->groupBy('commentable_id')->selectRaw('commentable_id, COUNT(*) as approved_review_count');
        $ranked = DB::table('order_items as items')
            ->join('orders as orders', 'orders.id', '=', 'items.order_id')
            ->join('order_statuses as statuses', 'statuses.id', '=', 'orders.status_id')
            ->join('products as products', 'products.id', '=', 'items.product_id')
            ->leftJoinSub($reviews, 'reviews', 'reviews.commentable_id', '=', 'products.id')
            ->where('products.is_active', true)->where('items.quantity', '>', 0)
            ->whereIn('products.manufacturer_id', fn ($query) => $query->select('manufacturer_id')->from('catalog_manufacturer_translations')->whereRaw('LOWER(name) = ?', ['cluse']))
            ->where('statuses.is_cancelled', false)
            ->where(fn ($query) => $query->where('statuses.code', 'herrera-oc-status-5')->orWhere('statuses.is_paid', true))
            ->whereIn('items.product_id', fn ($query) => $query->select('product_id')->from('category_product')->whereIn('category_id', $categoryIds))
            ->when($allowedIds !== null, fn ($query) => $query->whereIn('items.product_id', $allowedIds))
            ->groupBy('items.product_id')
            ->selectRaw('items.product_id, SUM(items.quantity) as purchased_quantity, MAX(COALESCE(reviews.approved_review_count, 0)) as approved_review_count')
            ->orderByDesc('purchased_quantity')
            ->orderByDesc('approved_review_count')
            // Stock only breaks equal sales totals; it never changes the sales ranking.
            ->orderByRaw('MAX(CASE WHEN products.stock_qty > 0 THEN 1 ELSE 0 END) DESC')
            ->orderBy('items.product_id')->get();

        return $ranked->pluck('product_id')->map(fn ($id) => (int) $id);
    }

    /** Rank women's CLUSE watches by completed purchases, using reviews only to break sales ties. */
    public function products(int $limit = 8): Collection
    {
        $limit = max(1, min(50, $limit));
        $locales = array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]));
        $ranked = $this->rankedProductIds()->map(fn ($id) => (object) ['product_id' => $id]);

        $selection = collect();
        foreach ($ranked->chunk(30) as $batch) {
            $products = Product::query()->whereIn('id', $batch->pluck('product_id'))
                ->withStorefrontEnergyData()->withApprovedCommentSummary(['hr'])
                ->with([
                    'media', 'taxRate', 'translations', 'manufacturer.translations', 'categories.translations',
                    'attributes' => \App\Support\ProductMaterialLabel::eagerLoadAttributes($locales[0], $locales[1] ?? $locales[0]),
                ])->get()->keyBy('id');
            foreach ($batch as $rank) {
                $product = $products->get($rank->product_id);
                $translation = $product?->translations->firstWhere('locale', $locales[0])
                    ?? $product?->translations->firstWhere('locale', $locales[1] ?? $locales[0]);
                $image = $product ? LegacyCatalogImage::first($product) : null;
                if (! $product || ! $translation?->slug || ! $image) {
                    continue;
                }
                $selection->push($product);
                if ($selection->count() >= $limit) {
                    return $selection;
                }
            }
        }

        return $selection;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function slides(?User $user = null, int $limit = 6, ?Collection $products = null): Collection
    {
        $locales = array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]));

        return ($products ?? $this->products())->take(max(1, min(6, $limit)))->map(function (Product $product) use ($locales, $user): array {
            $translation = $product->translations->firstWhere('locale', $locales[0])
                ?? $product->translations->firstWhere('locale', $locales[1] ?? $locales[0]);
            $brand = trim((string) ($product->manufacturer?->translations->firstWhere('locale', $locales[0])?->name
                ?? $product->manufacturer?->translations->firstWhere('locale', $locales[1] ?? $locales[0])?->name));
            $title = trim(preg_replace('/^(?:Ženski|Muški|Dječji|Pametni)\s+sat(?:ovi)?\s+/iu', '', $translation->name) ?? $translation->name);
            if ($brand !== '') {
                $title = trim(preg_replace('/^'.preg_quote($brand, '/').'\s+/iu', '', $title) ?? $title);
            }
            $title = trim(preg_replace('/^(?:(?:Ženski|Muški|Dječji|Pametni)\s+)?sat(?:ovi)?\s+/iu', '', $title) ?? $title);
            $model = trim((string) (data_get($product->payload, 'opencart.model') ?: $product->sku));
            if ($model !== '') {
                $withoutModel = trim(preg_replace('/\s*'.preg_quote($model, '/').'\s*$/iu', '', $title) ?? $title);
                $title = $withoutModel !== '' ? $withoutModel : $title;
            }
            $price = $this->prices->forProduct($product, $user);
            $ratingCount = max(0, (int) $product->approved_comments_count);
            $ratingAverage = $product->approved_comments_avg_rating;

            return [
                'product' => $product, 'brand' => $brand, 'title' => $title,
                'model' => $model,
                'image' => LegacyCatalogImage::first($product), 'url' => route('products.show', ['slug' => $translation->slug]),
                'price' => $price['can_view_price'] ? number_format((float) $price['display_current'], 2, ',', '.').' €' : null,
                'old_price' => $price['can_view_price'] && $price['display_old'] !== null ? number_format((float) $price['display_old'], 2, ',', '.').' €' : null,
                'in_stock' => (float) $product->stock_qty > 0,
                'rating_count' => $ratingCount,
                'rating_average' => $ratingCount > 0 && $ratingAverage !== null ? (float) $ratingAverage : null,
            ];
        })->values();
    }
}
