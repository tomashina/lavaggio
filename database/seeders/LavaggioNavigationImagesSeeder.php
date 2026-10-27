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
            'deterdzenti' => 'Kombinacija Frosch deterdženata za univerzalno, šareno i osjetljivo rublje',
            'uklanjanje-mrlja' => 'Frosch sprej za predtretman i sredstvo za uklanjanje mrlja',
            'mirisi-i-njega-rublja' => 'Frosch omekšivači s mirisom badema, hibiskusa i pamuka',
            'higijena-i-odrzavanje' => 'Frosch Citrus sredstvo za higijenu i održavanje perilice',
            'pribor-i-dodaci-za-rublje' => 'Mrežica za pranje, vunene kuglice, odstranjivač mucica i valjak za dlačice',
        ];

        foreach ($defaults as $code => $alt) {
            $categoryId = Category::query()->where('scope', Category::SCOPE_CATALOG)
                ->whereNull('parent_id')->where('code', 'lavaggio-'.$code)->value('id');
            // An explicit empty path is an administrator's choice to remove a photo.
            if ($categoryId && ! array_key_exists((int) $categoryId, $images)) {
                $images[(int) $categoryId] = [
                    'image_path' => '/assets/lavaggio/images/categories/'.$code.'.webp',
                    'image_alt' => $alt,
                ];
            }
        }

        app(SystemSettingsService::class)->put(NavigationMenuService::CATEGORY_IMAGES_SETTINGS_KEY, $images);
    }
}
