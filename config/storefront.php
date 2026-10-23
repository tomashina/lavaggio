<?php

return [
    // Presentation is independent of the store name, logo and commerce rules.
    // Auto preserves the original brand-based choice for existing installations.
    'theme' => env('STOREFRONT_THEME', 'auto'),
    'home' => env('STOREFRONT_HOME', 'auto'),
    'brand' => env('STOREFRONT_BRAND', 'auto'),
];
