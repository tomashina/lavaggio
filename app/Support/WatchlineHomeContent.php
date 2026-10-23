<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Support\Media\LegacyCatalogImage;
use Illuminate\Support\Collection;

/** Supplies Watchline records to the unchanged Liber Media homepage module layouts. */
class WatchlineHomeContent
{
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
