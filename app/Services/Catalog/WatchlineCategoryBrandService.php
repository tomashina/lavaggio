<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Support\Media\ManufacturerLogo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WatchlineCategoryBrandService
{
    /**
     * The caller supplies the visible category and all of its visible descendants.
     * Request filters must not narrow this category navigation.
     *
     * @param  array<int, int>  $categoryIds
     * @return Collection<int, array{id: int, name: string, slug: string, logo: ?string, variant: string}>
     */
    public function forCategoryScope(array $categoryIds, string $locale, string $fallbackLocale, bool $hideOutOfStock = false): Collection
    {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), fn (int $id): bool => $id > 0)));
        if ($categoryIds === []) {
            return collect();
        }

        $locales = array_values(array_unique([$locale, $fallbackLocale]));

        return Manufacturer::query()
            ->where('is_active', true)
            ->whereHas('products', fn (Builder $products) => $products
                ->visibleOnStorefront($hideOutOfStock)
                ->whereHas('categories', fn (Builder $categories) => $categories->whereIn('categories.id', $categoryIds)))
            ->with(['translations', 'media'])
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (Manufacturer $manufacturer) use ($locales): ?array {
                $translation = collect($locales)
                    ->map(fn (string $locale) => $manufacturer->translations->firstWhere('locale', $locale))
                    ->first(fn ($translation): bool => $translation && trim((string) $translation->slug) !== '');
                if (! $translation) {
                    return null;
                }

                $logo = ManufacturerLogo::resolve($manufacturer);

                return [
                    'id' => (int) $manufacturer->id,
                    'name' => trim((string) ($translation->name ?: $manufacturer->code)),
                    'slug' => (string) $translation->slug,
                    'logo' => $logo['url'],
                    'variant' => $logo['variant'],
                ];
            })->filter()->values();
    }
}
