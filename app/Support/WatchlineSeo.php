<?php

namespace App\Support;

use App\Models\Catalog\Product\Product;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;

/** Presentation-only SEO policy; never changes catalog facts or public visibility. */
class WatchlineSeo
{
    public static function canonical(Request $request, ?Paginator $pagination = null): string
    {
        $query = [];
        // A blog topic is an editorial landing page, rather than a product facet.
        if ($request->routeIs('blog.index') && is_string($request->query('category')) && trim($request->query('category')) !== '') {
            $query['category'] = trim($request->query('category'));
        }
        if ($pagination && $pagination->currentPage() > 1) {
            $query['page'] = $pagination->currentPage();
        }

        return $request->url().($query !== [] ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    public static function robots(Request $request, string $configured): string
    {
        if (SearchEngineIndexing::shouldBlock($request)) {
            return 'noindex,nofollow';
        }
        if (preg_match('/\bnoindex\b/i', $configured)) {
            return $configured;
        }
        $private = $request->routeIs('front.auth.*', 'account.*', 'front.account.*', 'cart.*', 'checkout.*', 'wishlist.*',
            'gift-vouchers.purchase', 'watchline.messages.*', 'stock-notifications.*');
        $filtered = false;
        if ($request->routeIs('shop.index', 'categories.show', 'manufacturers.show', 'gift-guide.index', 'blog.index')) {
            foreach ($request->query() as $key => $value) {
                if (in_array($key, ['q', 'sort', 'cols', 'size', 'price_min', 'price_max', 'manufacturer', 'brand',
                    'available_only', 'promo_only', 'recipient', 'type', 'budget'], true)
                    || ($key === 'category' && ! $request->routeIs('blog.index'))
                    || str_starts_with($key, 'attr_') || str_starts_with($key, 'opt_')) {
                    if ($value !== null && $value !== '' && $value !== [] && ! in_array($value, ['0', 0, false, 'all'], true)) {
                        $filtered = true;
                        break;
                    }
                }
            }
        }
        if (! $private && ! $filtered) {
            return $configured;
        }
        $parts = array_filter(array_map('trim', explode(',', $configured)), fn (string $part): bool => strtolower($part) !== 'index' && $part !== '');
        array_unshift($parts, 'noindex');
        if (! in_array('follow', $parts, true) && ! in_array('nofollow', $parts, true)) {
            $parts[] = 'follow';
        }

        return implode(',', array_unique($parts));
    }

    /** Only emit an actual numeric GTIN with a valid GS1 check digit. */
    public static function identifiers(Product $product): array
    {
        $barcode = trim((string) $product->barcode);
        $length = strlen($barcode);
        if (! in_array($length, [8, 12, 13, 14], true) || ! ctype_digit($barcode) || trim($barcode, '0') === '') {
            return [];
        }
        $sum = 0;
        for ($index = $length - 2, $position = 0; $index >= 0; $index--, $position++) {
            $sum += (int) $barcode[$index] * ($position % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === (int) $barcode[$length - 1] ? ['gtin'.$length => $barcode] : [];
    }
}
