<?php

namespace App\Support;

class WatchlineGiftGuideNavigation
{
    /** Adds the guide without replacing existing admin-authored navigation fields. */
    public static function addTo(array $items): array
    {
        $guideUrl = route('gift-guide.index', absolute: false);
        $voucherPath = parse_url(route('gift-vouchers.create', absolute: false), PHP_URL_PATH);
        $items = array_values($items);
        $voucherIndex = null;
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $urls = [(string) ($item['url'] ?? ''), ...array_values((array) ($item['url_translations'] ?? []))];
            foreach ($urls as $url) {
                $path = parse_url((string) $url, PHP_URL_PATH);
                if ($path === $guideUrl) {
                    return $items;
                }
                if ($path === $voucherPath && $voucherIndex === null) {
                    $voucherIndex = $index;
                }
            }
        }
        $sortOrder = $voucherIndex !== null
            ? (int) ($items[$voucherIndex]['sort_order'] ?? $voucherIndex)
            : (int) collect($items)->max('sort_order') + 1;
        $guide = ['type' => 'custom', 'label' => 'Tražiš poklon?', 'url' => $guideUrl,
            'icon' => 'gift', 'align_right' => true, 'is_active' => true, 'sort_order' => $sortOrder];
        array_splice($items, $voucherIndex ?? count($items), 0, [$guide]);

        return $items;
    }
}
