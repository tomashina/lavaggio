<?php

namespace Database\Seeders;

use App\Models\Catalog\Category\Category;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Seeder;

class LavaggioNavigationImagesSeeder extends Seeder
{
    public function run(): void
    {
        $images = app(NavigationMenuService::class)->categoryImages();
        $defaults = [
            'deterdzenti' => 'Deterdženti Ariel, Persil i Frosch',
            'uklanjanje-mrlja' => 'Sredstva za uklanjanje mrlja Vanish, Dr. Beckmann i Frosch',
            'mirisi-i-njega-rublja' => 'Omekšivači Lenor, Coccolino i Frosch',
            'higijena-i-odrzavanje' => 'Sredstva za održavanje perilice Calgon, Dr. Beckmann i Frosch',
            'pribor-i-dodaci-za-rublje' => 'Mrežica za pranje, vunene kuglice, odstranjivač mucica i valjak za dlačice',
        ];

        foreach ($defaults as $code => $alt) {
            $categoryId = Category::query()->where('scope', Category::SCOPE_CATALOG)
                ->whereNull('parent_id')->where('code', 'lavaggio-'.$code)->value('id');
            // An explicit empty path is an administrator's choice to remove a photo.
            if ($categoryId && ! array_key_exists((int) $categoryId, $images)) {
                $images[(int) $categoryId] = [
                    'image_path' => '/assets/lavaggio/images/categories/'.$code.($code === 'pribor-i-dodaci-za-rublje' ? '' : '-mix').'.webp',
                    'image_alt' => $alt,
                ];
            }
        }

        app(SystemSettingsService::class)->put(NavigationMenuService::CATEGORY_IMAGES_SETTINGS_KEY, $images);
    }
}
