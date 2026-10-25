<?php

namespace App\Support\Media;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Services\Import\WatchlineImageVariantService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class LegacyCatalogImage
{
    /** Validated original path for local media inventories and transfers. */
    public static function relativePath(mixed $source): ?string
    {
        return self::path($source);
    }

    /** A safe original catalog URL, without a server-side network request. */
    public static function url(mixed $source, ?int $width = null): ?string
    {
        $path = self::path($source);
        if ($path === null) {
            return null;
        }

        $root = realpath((string) config('legacy_media.local_root', public_path('image')));
        $local = $root !== false ? realpath($root.'/'.$path) : false;
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        if ($local !== false && str_starts_with($local, $root.'/') && is_file($local) && filesize($local) > 0) {
            $variant = $width === null ? null : self::variant($path, $width);

            return $variant['url'] ?? asset('image/'.$encoded);
        }

        $origin = self::origin();

        return (bool) config('legacy_media.enabled', false) && $origin !== null
            ? $origin.'/image/'.$encoded
            : null;
    }

    /** Only existing offline variants; never generates files or contacts a provider. */
    public static function variant(mixed $source, int $width): ?array
    {
        $path = self::path($source);
        // Gallery URLs have already been localized. Accept only known shop
        // image origins here; source() still enforces a real local original.
        if ($path === null && is_string($source)) {
            $reference = WatchlineMediaReference::classify($source);
            $path = ($reference['kind'] ?? '') === 'opencart' ? ($reference['path'] ?? null) : null;
        }

        return $path === null ? null : app(WatchlineImageVariantService::class)->variant($path, $width);
    }

    /** Truthful width descriptors, including sources smaller than a requested width. */
    public static function responsiveVariant(mixed $source, int $preferredWidth = 480): ?array
    {
        $small = self::variant($source, 480);
        $large = self::variant($source, 960);
        $selected = $preferredWidth >= 960 ? ($large ?? $small) : ($small ?? $large);
        if ($selected === null) {
            return null;
        }

        return $selected + [
            'srcset' => collect([$small, $large])->filter()->unique('width')
                ->map(fn ($variant) => $variant['url'].' '.$variant['width'].'w')->implode(', '),
            'thumb' => self::variant($source, 160)['url'] ?? $selected['url'],
        ];
    }

    /** Prefer usable uploaded media; only imported OpenCart records have a fallback. */
    public static function first(Model $model, array $conversions = [], bool $preferWebp = false, ?int $width = null): ?string
    {
        $collections = match (true) {
            $model instanceof Product => ['product_main', 'product_gallery'],
            $model instanceof Category => ['category_banner', 'category_icon'],
            $model instanceof Manufacturer => ['manufacturer_logo'],
            $model instanceof BlogPost => ['blog_cover', 'blog_gallery'],
            default => [],
        };

        foreach ($collections as $collection) {
            foreach ($model->getMedia($collection) as $media) {
                if (! MediaUrl::hasUsableSource($media, $conversions)) {
                    continue;
                }

                foreach ($conversions as $conversion) {
                    if ($url = MediaUrl::conversionOrNull($media, $conversion, $preferWebp)) {
                        return $url;
                    }
                }

                if (MediaUrl::hasUsableOriginal($media)) {
                    return (string) $media->getUrl();
                }
            }
        }

        // Preview category artwork can reference an imported product instead
        // of an original category image. Resolve its remote image on staging.
        if ($model instanceof Category) {
            $previewProductId = $model->getMedia('category_banner')->merge($model->getMedia('category_icon'))
                ->map(fn ($media) => (int) $media->getCustomProperty('preview_product_id', 0))
                ->first(fn ($id) => $id > 0);
            if ($previewProductId && ($product = Product::query()->find($previewProductId))) {
                if ($image = self::first($product, $conversions, $preferWebp, $width)) {
                    return $image;
                }
            }
        }

        return self::url(data_get($model->payload, 'opencart.image'), $width);
    }

    /** @return Collection<int, string> */
    public static function gallery(Product $product, ?int $width = null): Collection
    {
        $gallery = data_get($product->payload, 'opencart.gallery_images', []);

        return collect([data_get($product->payload, 'opencart.image')])
            ->merge(collect(is_array($gallery) ? $gallery : [])
                ->sortBy(fn ($item) => is_array($item) ? ($item['sort_order'] ?? 0) : 0)
                ->map(fn ($item) => is_array($item) ? ($item['image'] ?? null) : $item))
            ->map(static fn ($path): ?string => self::url($path, $width))
            ->filter()
            ->unique()
            ->values();
    }

    private static function path(mixed $source): ?string
    {
        if (! is_string($source) || trim($source) === '') {
            return null;
        }

        $source = trim($source);
        if (str_contains($source, '://')) {
            $url = parse_url($source);
            $origin = self::origin();
            if ($url === false || $origin === null || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment'])
                || strtolower((string) ($url['scheme'] ?? '')).'://'.strtolower((string) ($url['host'] ?? '')) !== $origin
                || (isset($url['port']) && (int) $url['port'] !== 443)) {
                return null;
            }

            $source = (string) ($url['path'] ?? '');
        }

        $source = rawurldecode($source);
        if (preg_match('/[\x00-\x1f\x7f\\\\?#:%]/', $source)) {
            return null;
        }

        $source = ltrim($source, '/');
        if (str_starts_with($source, 'image/')) {
            $source = substr($source, 6);
        }

        $segments = explode('/', $source);
        if (! in_array($segments[0] ?? '', (array) config('legacy_media.directories', ['catalog']), true) || count($segments) < 2
            || count(array_intersect($segments, ['', '.', '..'])) > 0
            || ! in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp'], true)) {
            return null;
        }

        return $source;
    }

    private static function origin(): ?string
    {
        $source = rtrim(trim((string) config('legacy_media.origin', '')), '/');
        $url = parse_url($source);

        if ($url === false || strtolower((string) ($url['scheme'] ?? '')) !== 'https'
            || ! isset($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['port']) || isset($url['path']) || isset($url['query']) || isset($url['fragment'])) {
            return null;
        }

        return 'https://'.strtolower($url['host']);
    }
}
