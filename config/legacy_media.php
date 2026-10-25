<?php

return [
    // Temporary browser-side fallback. PHP never fetches a remote image.
    'remote_only' => env('LEGACY_MEDIA_REMOTE_ONLY', false),
    'enabled' => env('LEGACY_MEDIA_ENABLED', false),
    'directories' => array_values(array_filter(explode(',', env('LEGACY_MEDIA_DIRECTORIES', 'catalog')))),
    'origin' => env('LEGACY_MEDIA_ORIGIN', 'https://www.herrera.hr'),
    // Originals copied here at the final media-transfer step win over live URLs.
    'local_root' => public_path('image'),
    // Offline generated local variants are optional; originals stay the default.
    'variants_enabled' => env('LEGACY_MEDIA_VARIANTS_ENABLED', false),
    'variants_quality' => 82,
    'variants_index_root' => storage_path('app/private/watchline/image-variants'),
];
