<?php

declare(strict_types=1);

// Include rendered storefront icons and the Watchline widget/navigation defaults.
// Keep custom CMS/admin symbols available through the existing full-sprite fallback.
$icons = [
    'solid' => [
        'arrow-left',
        'arrow-right',
        'arrow-up',
        'arrow-up-right',
        'bag-shopping',
        'bars',
        'bars-staggered',
        'bell',
        'book-open',
        'check',
        'chevron-down',
        'chevron-left',
        'chevron-right',
        'chevron-up',
        'circle-check',
        'circle-info',
        'cookie-bite',
        'credit-card',
        'envelope',
        'file-arrow-down',
        'file-lines',
        'file-pdf',
        'gift',
        'grip',
        'heart',
        'link',
        'list',
        'list-check',
        'lock',
        'magnifying-glass',
        'minus',
        'plus',
        'rotate-left',
        'rotate-right',
        'scissors',
        'shapes',
        'shield-halved',
        'sliders',
        'star',
        'table-cells',
        'table-cells-large',
        'table-columns',
        'tag',
        'ticket',
        'triangle-exclamation',
        'truck-fast',
        'user',
        'user-check',
        'user-plus',
        'xmark',
    ],
    'regular' => [
        'arrow-right',
        'badge-check',
        'basket-shopping',
        'chevron-down',
        'circle-user',
        'comments',
        'credit-card',
        'envelope',
        'gift',
        'heart',
        'list-check',
        'magnifying-glass',
        'phone',
        'rotate-left',
        'screwdriver-wrench',
        'ticket',
        'truck-fast',
        'user',
        'xmark',
    ],
    'light' => [
        'bag-shopping',
        'basket-shopping',
        'battery-half',
        'bolt',
        'boxes-stacked',
        'candle-holder',
        'car-bolt',
        'clock',
        'comment',
        'credit-card',
        'faucet',
        'gift',
        'heart-pulse',
        'helmet-safety',
        'house',
        'kitchen-set',
        'lightbulb',
        'lightbulb-on',
        'location-dot',
        'magnifying-glass',
        'outlet',
        'reel',
        'ruler',
        'screwdriver-wrench',
        'sensor',
        'shield-check',
        'shirt',
        'spray-can-sparkles',
        'tag',
        'temperature-half',
        'toolbox',
        'truck-fast',
        'tv',
        'user',
        'washing-machine',
        'wind',
    ],
    'duotone' => ['shirt', 'washing-machine', 'comments'],
    'brands' => [
        'facebook',
        'facebook-f',
        'instagram',
        'linkedin',
        'linkedin-in',
        'tiktok',
        'whatsapp',
        'x-twitter',
        'youtube',
    ],
];

$projectRoot = dirname(__DIR__);
$assetDirectory = $projectRoot.'/public/vendor/fontawesome-pro-7.3.1';
$sourceDirectory = $assetDirectory.'/sprites';
$targetDirectory = $assetDirectory.'/storefront-sprites';

if (! is_dir($targetDirectory) && ! mkdir($targetDirectory, 0755, true) && ! is_dir($targetDirectory)) {
    throw new RuntimeException('Unable to create storefront sprite directory.');
}

foreach ($icons as $style => $names) {
    $source = file_get_contents($sourceDirectory.'/'.$style.'.svg');
    if (! is_string($source)) {
        throw new RuntimeException("Unable to read {$style} sprite.");
    }

    preg_match_all('/<symbol\\s+id="([^"]+)"[^>]*>.*?<\\/symbol>/s', $source, $matches, PREG_SET_ORDER);
    $symbols = [];

    foreach ($matches as $match) {
        $symbol = (string) $match[0];

        // The original 7.3.1 bag artwork spans y=-32..480. Keep its paths
        // unchanged and include the complete handle in the generated viewport.
        if ($match[1] === 'bag-shopping' && in_array($style, ['solid', 'regular'], true)) {
            $symbol = preg_replace('/viewBox="[^"]+"/', 'viewBox="0 -32 448 512"', $symbol, 1) ?? $symbol;
        }

        $symbols[(string) $match[1]] = $symbol;
    }

    $selected = [];
    foreach ($names as $name) {
        if (! isset($symbols[$name])) {
            throw new RuntimeException("Missing {$style} icon: {$name}");
        }

        $selected[] = $symbols[$name];
    }

    $sprite = implode("\n", [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<!-- Font Awesome Pro 7.3.1 by Fonticons, Inc. Commercial license: ../LICENSE.txt -->',
        '<svg xmlns="http://www.w3.org/2000/svg" style="display: none;">',
        ...array_map(static fn (string $symbol): string => '  '.$symbol, $selected),
        '</svg>',
        '',
    ]);

    if (file_put_contents($targetDirectory.'/'.$style.'.svg', $sprite) === false) {
        throw new RuntimeException("Unable to write {$style} storefront sprite.");
    }
}

if (file_put_contents($targetDirectory.'/manifest.json', json_encode($icons, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) {
    throw new RuntimeException('Unable to write storefront sprite manifest.');
}
