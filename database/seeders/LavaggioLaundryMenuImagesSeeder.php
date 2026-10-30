<?php

namespace Database\Seeders;

use App\Models\Content\Page\InfoPage;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Seeder;

class LavaggioLaundryMenuImagesSeeder extends Seeder
{
    public function run(): void
    {
        $manifest = json_decode(file_get_contents(database_path('data/lavaggio-laundry-menu-images.json')), true, flags: JSON_THROW_ON_ERROR);
        $imagesByCode = collect($manifest['images'])->keyBy(fn (array $image): string => 'lavaggio-modx-'.$image['source_page_id']);
        $imagesByPage = InfoPage::query()->whereIn('code', $imagesByCode->keys())->get()
            ->mapWithKeys(fn (InfoPage $page): array => [$page->id => $imagesByCode[$page->code]]);
        $settings = app(SystemSettingsService::class);
        $items = $settings->get(NavigationMenuService::SETTINGS_KEY, []);
        $assignImages = function (array $items) use (&$assignImages, $imagesByPage): array {
            foreach ($items as &$item) {
                $image = $imagesByPage[(int) ($item['page_id'] ?? 0)] ?? null;
                if ($image && ! empty($item['children']) && trim((string) ($item['desktop_promo_image_path'] ?? '')) === '') {
                    $item['desktop_promo_image_path'] = $image['image_path'];
                }
                if (! empty($item['children'])) {
                    $item['children'] = $assignImages($item['children']);
                }
            }

            return $items;
        };

        $settings->put(NavigationMenuService::SETTINGS_KEY, $assignImages(is_array($items) ? $items : []));
    }
}
