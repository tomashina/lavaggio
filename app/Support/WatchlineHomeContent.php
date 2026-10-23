<?php

namespace App\Support;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Support\Media\LegacyCatalogImage;
use Illuminate\Support\Collection;

/** Supplies Watchline records to the unchanged Liber Media homepage module layouts. */
class WatchlineHomeContent
{
    /** @return Collection<int, Product> */
    public function guessProducts(int $limit = 12): Collection
    {
        $limit = max(0, min(50, $limit));
        if ($limit === 0) {
            return collect();
        }

        $locales = array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale', 'hr')]));
        $key = 'watchline.home.guess-products.'.implode('.', $locales);
        if (! request()->attributes->has($key)) {
            $manufacturer = $this->guessManufacturer();
            $selected = collect();
            if ($manufacturer) {
                $category = $this->guessCategory();
                $categoryIds = $category ? Category::descendantsAndSelf($category->id)
                    ->where('scope', Category::SCOPE_CATALOG)->where('is_active', true)->pluck('id') : null;
                $query = Product::query()->where('is_active', true)->where('stock_qty', '>', 0)
                    ->where('manufacturer_id', $manufacturer->id)
                    ->whereHas('translations', fn ($query) => $query->whereIn('locale', $locales)
                        ->whereNotNull('slug')->whereRaw("TRIM(slug) <> ''"))
                    ->when($categoryIds !== null, fn ($query) => $query
                        ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $categoryIds)))
                    ->withStorefrontEnergyData()->withApprovedCommentSummary(['hr'])
                    ->with([
                        'media', 'taxRate', 'translations', 'manufacturer.translations', 'categories.translations',
                        'attributes' => ProductMaterialLabel::eagerLoadAttributes($locales[0], $locales[1] ?? $locales[0]),
                    ])
                    // Preserve the source chronology rather than the shared import timestamp.
                    ->orderByDesc('payload->opencart->date_added')->orderByDesc('products.id');

                $selected = $query->get()->filter(function (Product $product) use ($locales): bool {
                    $translation = $product->translations->firstWhere('locale', $locales[0])
                        ?? $product->translations->firstWhere('locale', $locales[1] ?? $locales[0]);

                    return trim((string) $translation?->slug) !== '' && LegacyCatalogImage::first($product) !== null;
                })->take(50)->values();
            }
            request()->attributes->set($key, $selected);
        }

        return request()->attributes->get($key)->take($limit)->values();
    }

    public function guessCategoryUrl(): string
    {
        $category = $this->guessCategory();
        if ($category) {
            return route('categories.show', ['slug' => $category->translations->firstWhere('locale', 'hr')->slug], false);
        }

        // OpenCart's guess-satovi URL is a manufacturer page in the imported catalog.
        $translation = $this->guessManufacturer()?->translations->firstWhere('locale', 'hr');

        return $translation?->slug
            ? route('manufacturers.show', ['slug' => $translation->slug], false)
            : route('manufacturers.index', absolute: false);
    }

    private function guessCategory(): ?Category
    {
        $key = 'watchline.home.guess-category';
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, Category::query()->where('scope', Category::SCOPE_CATALOG)
                ->where('is_active', true)
                ->whereHas('translations', fn ($query) => $query->where('locale', 'hr')->where('slug', 'guess-satovi'))
                ->with('translations')->first());
        }

        return request()->attributes->get($key);
    }

    private function guessManufacturer(): ?Manufacturer
    {
        $key = 'watchline.home.guess-manufacturer';
        if (! request()->attributes->has($key)) {
            request()->attributes->set($key, Manufacturer::query()->where('is_active', true)
                ->whereHas('translations', fn ($query) => $query->where('locale', 'hr')
                    ->where(fn ($query) => $query->where('slug', 'guess-satovi')->orWhereRaw('LOWER(name) = ?', ['guess'])))
                ->with('translations')->first());
        }

        return request()->attributes->get($key);
    }

    public function campaignProduct(int $productId): ?Product
    {
        $product = Product::query()->where('is_active', true)->with(['media', 'translations'])->find($productId);

        return $product && LegacyCatalogImage::first($product) !== null ? $product : null;
    }

    /** The two CLUSE models selected for the homepage campaign, in display order. */
    public function cluseCampaignWatches(?Collection $ranked = null): Collection
    {
        $ranked ??= collect();
        $selected = collect();

        foreach (['CW11812', 'CW11502'] as $model) {
            $matches = static fn (mixed $value): bool => strtoupper(trim((string) $value)) === $model;
            $product = $ranked->first(fn ($product) => $product instanceof Product && $product->is_active && $matches($product->sku))
                ?? $ranked->first(fn ($product) => $product instanceof Product && $product->is_active && $matches(data_get($product->payload, 'opencart.model')));

            if (! $product) {
                $product = Product::query()->where('is_active', true)
                    ->where(fn ($query) => $query->where('sku', $model)->orWhere('payload->opencart->model', $model))
                    ->with(['media', 'translations'])->orderBy('id')->get()
                    ->first(fn (Product $product) => LegacyCatalogImage::first($product) !== null);
            }

            if ($product && LegacyCatalogImage::first($product) !== null) {
                $selected->push($product);
            }
        }

        return $selected;
    }

    public function categoryImage(Category $category): ?string
    {
        $key = 'watchline.home.category-image.'.$category->id;
        if (! request()->attributes->has($key)) {
            $ids = Category::descendantsAndSelf($category->id)->pluck('id');
            $products = Product::query()->where('is_active', true)
                ->whereHas('categories', fn ($query) => $query->whereIn('categories.id', $ids))
                ->whereNotNull('payload->opencart->image')->where('payload->opencart->image', '!=', '')
                ->with('media')->orderByRaw('CASE WHEN stock_qty > 0 THEN 0 ELSE 1 END')->orderBy('id')->limit(8)->get();
            $image = $products->map(fn (Product $product) => LegacyCatalogImage::first($product))->first(fn ($url) => $url !== null);
            request()->attributes->set($key, $image);
        }

        return request()->attributes->get($key);
    }

    /** @return Collection<int, Product> */
    public function products(): Collection
    {
        $key = 'watchline.home.module-products';
        if (! request()->attributes->has($key)) {
            $query = Product::where('is_active', true)->where('stock_qty', '>', 0);
            $ids = (clone $query)->selectRaw('MIN(id) as id')->groupBy('manufacturer_id')->orderBy('id')->limit(8)->pluck('id');
            if ($ids->count() < 8) {
                $ids = $ids->concat((clone $query)->whereNotIn('id', $ids)->orderBy('id')->limit(8 - $ids->count())->pluck('id'));
            }
            request()->attributes->set($key, (clone $query)->whereIn('id', $ids)->with(['media', 'translations'])->orderBy('id')->get());
        }

        return request()->attributes->get($key);
    }

    public function image(int $index): ?string
    {
        $product = $this->products()->get($index % max(1, $this->products()->count()));

        return $product ? LegacyCatalogImage::first($product) : null;
    }

    public function productUrl(int $index): string
    {
        $product = $this->products()->get($index % max(1, $this->products()->count()));
        $translation = $product?->translations->firstWhere('locale', app()->getLocale()) ?? $product?->translations->first();

        return $translation?->slug ? route('products.show', ['slug' => $translation->slug], false) : route('shop.index', absolute: false);
    }

    public function informationUrl(string $titleFragment): string
    {
        $page = InfoPage::where('is_active', true)->whereHas('translations', fn ($query) => $query
            ->where('locale', 'hr')->where('title', 'like', '%'.$titleFragment.'%'))->with('translations')->first();
        $translation = $page?->translations->firstWhere('locale', 'hr');

        return $translation?->slug ? route('pages.show', ['slug' => $translation->slug], false) : route('contact.create', absolute: false);
    }
}
