<?php

namespace Database\Seeders;

use App\Models\Content\Page\InfoPage;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Seeder;
use RuntimeException;

class LavaggioContentNavigationSeeder extends Seeder
{
    public function run(): void
    {
        $pages = InfoPage::query()->where('code', 'like', 'lavaggio-modx-%')->get()->keyBy('code');
        $page = function (int $sourceId, string $label, array $children = []) use ($pages): array {
            $record = $pages->get('lavaggio-modx-'.$sourceId);
            if (! $record) {
                throw new RuntimeException('Najprije uvezite MODX stranicu '.$sourceId.'.');
            }

            return [
                'type' => 'page', 'page_id' => (int) $record->id,
                'label' => $label, 'label_translations' => ['hr' => $label],
                'is_active' => true, 'children' => $children,
            ];
        };

        $items = [
            $page(68, 'Praonica', [
                $page(69, 'Samoposlužna praonica rublja', [
                    $page(92, 'Kako prati rublje kod nas'),
                    $page(154, 'Kako pripremiti rublje za pranje'),
                    $page(155, 'Koji program odabrati'),
                ]),
                $page(73, 'Uslužna praonica rublja', [
                    $page(265, 'Pranje i peglanje košulja'),
                    $page(157, 'Pranje i peglanje kućnog rublja'),
                    $page(156, 'Uslužno pranje i peglanje za iznajmljivače'),
                    $page(159, 'Pranje i dezinfekcija jastuka'),
                    $page(246, 'Pranje popluna i deka'),
                    $page(268, 'Uklanjanje neugodnih mirisa'),
                    $page(158, 'Uslužno pranje za vrtiće'),
                    $page(311, 'Pranje i održavanje stolnog i kuhinjskog rublja za restorane'),
                ]),
                $page(75, 'Prikup i dostava rublja', [
                    $page(168, 'Barban'), $page(163, 'Žminj'), $page(178, 'Marčana'),
                    $page(164, 'Kanfanar'), $page(179, 'Svetvinčenat'), $page(180, 'Vodnjan'),
                ]),
                $page(76, 'Ozonsko pranje rublja', [
                    $page(169, 'Kako funkcionira'), $page(170, 'Prednosti'),
                ]),
                $page(267, 'Što peremo u našoj praonici'),
                $page(359, 'Rezervacije 2027'),
                $page(70, 'Cjenik', [
                    $page(87, 'Cjenik samoposlužna'), $page(88, 'Cjenik uslužna'), $page(322, 'Poklon bon'),
                ]),
                $page(71, 'O praonici', [
                    $page(174, 'Radno vrijeme praonice'), $page(78, 'Recenzije klijenata'), $page(85, 'Kućni red u praonici'),
                ]),
            ]),
            ['type' => 'faq', 'label' => 'Pitanja i odgovori', 'is_active' => true],
            ['type' => 'blog', 'label' => 'TV studio', 'is_active' => true],
            $page(153, 'Tko smo mi'),
            ['type' => 'contact', 'label' => 'Kontakt', 'is_active' => true],
        ];
        $withOrder = function (array $items) use (&$withOrder): array {
            foreach ($items as $index => &$item) {
                $item['sort_order'] = $index * 10;
                if (! empty($item['children'])) {
                    $item['children'] = $withOrder($item['children']);
                }
            }

            return $items;
        };

        app(SystemSettingsService::class)->putMany([
            NavigationMenuService::SETTINGS_KEY => $withOrder($items),
            'catalog_use_blog' => true,
            'store_footer_col_3_custom_links' => 'Moj račun|'.route('front.auth.login', absolute: false)."\nKošarica|/cart\nPraonica|/page/usluge",
        ]);
    }
}
