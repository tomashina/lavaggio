<?php

namespace Tests\Feature\Front;

use App\Livewire\Admin\Content\Navigation\Manager;
use App\Models\Catalog\Category\Category;
use App\Models\Content\Page\InfoPage;
use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use Database\Seeders\LavaggioContentNavigationSeeder;
use Database\Seeders\LavaggioStorefrontSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioNestedNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.locale' => 'hr', 'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
        ]);
        app()->setLocale('hr');
        $this->seed(LavaggioStorefrontSeeder::class);
    }

    public function test_nested_pages_resolve_current_translations_and_hide_inactive_items(): void
    {
        $parent = $this->page('Praonica', 'praonica');
        $group = $this->page('Samoposlužna praonica rublja', 'samoposluzna');
        $guide = $this->page('Kako prati rublje kod nas', 'kako-prati');
        $inactive = $this->page('Skica', 'skica');
        $inactive->update(['is_active' => false]);
        $guide->translations()->create(['locale' => 'en', 'title' => 'How to wash', 'slug' => 'how-to-wash']);
        $this->settings([
            ['type' => 'page', 'page_id' => $parent->id, 'children' => [
                ['type' => 'page', 'page_id' => $group->id, 'children' => [
                    ['type' => 'page', 'page_id' => $inactive->id],
                    ['type' => 'page', 'page_id' => $guide->id],
                    ['type' => 'custom', 'label' => 'Skriveno', 'url' => '/hidden', 'is_active' => false],
                ]],
            ]],
            ['type' => 'faq', 'label' => 'ČPP'],
        ]);

        $navigation = app(NavigationMenuService::class);
        $items = $navigation->storefrontItemsForLocale('hr');
        $this->assertSame(['Web shop', 'Praonica', 'ČPP'], array_column($items, 'label'));
        $this->assertSame(['Kako prati rublje kod nas'], array_column($items[1]['children'][0]['children'], 'label'));
        $english = $navigation->storefrontItemsForLocale('en')[1]['children'][0]['children'][0];
        $this->assertSame('How to wash', $english['label']);
        $this->assertSame(route('pages.show', 'how-to-wash'), $english['url']);

        $guide->translations()->where('locale', 'hr')->update(['title' => 'Pranje kod nas', 'slug' => 'pranje-kod-nas']);
        $updated = $navigation->storefrontItemsForLocale('hr')[1]['children'][0]['children'][0];
        $this->assertSame('Pranje kod nas', $updated['label']);
        $this->assertSame(route('pages.show', 'pranje-kod-nas'), $updated['url']);
        $group->update(['is_active' => false]);
        $this->assertSame([], $navigation->storefrontItemsForLocale('hr')[1]['children']);
    }

    public function test_shop_and_laundry_have_unique_menu_panels_and_mobile_nested_links(): void
    {
        $root = Category::create(['scope' => 'catalog', 'code' => 'detergents', 'is_active' => true, 'show_in_menu' => true]);
        $root->translations()->create(['scope' => 'catalog', 'locale' => 'hr', 'name' => 'Deterdženti', 'slug' => 'deterdzenti']);
        $parent = $this->page('Praonica', 'praonica');
        $group = $this->page('Uslužna praonica', 'usluzna-praonica');
        $guide = $this->page('Pranje košulja', 'pranje-kosulja');
        $this->settings([['type' => 'page', 'page_id' => $parent->id, 'children' => [
            ['type' => 'page', 'page_id' => $group->id, 'children' => [['type' => 'page', 'page_id' => $guide->id]]],
        ]]]);

        $response = $this->get(route('home'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $menus = $xpath->query('//*[@data-lavaggio-catalog-menu]');
        $this->assertSame(2, $menus->length);
        $this->assertNotSame($menus->item(0)->getAttribute('id'), $menus->item(1)->getAttribute('id'));
        $this->assertSame(1, $xpath->query('//*[@id="lavaggio-web-shop-menu"]//a[@href="'.route('categories.show', 'deterdzenti').'"][@data-lavaggio-catalog-root]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-lavaggio-catalog-menu]//a[@href="'.route('pages.show', 'pranje-kosulja').'"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-mobile-menu-panel]//a[@href="'.route('pages.show', 'pranje-kosulja').'"]')->length);
        $ids = [];
        foreach ($xpath->query('//*[@data-lavaggio-catalog-menu or @data-lavaggio-catalog-panel]') as $node) {
            $ids[] = $node->getAttribute('id');
        }
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_admin_can_add_edit_reorder_translate_and_remove_nested_items(): void
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);
        $parent = $this->page('Praonica', 'praonica');
        $guide = $this->page('Pranje košulja', 'pranje-kosulja');
        $this->settings([['type' => 'page', 'page_id' => $parent->id]]);

        $component = Livewire::actingAs($user)->test(Manager::class)
            ->call('addChildItem', '0', 'custom')
            ->set('form.items.0.children.0.label', 'Usluge')
            ->set('form.items.0.children.0.url', '/usluge')
            ->call('addChildItem', '0.children.0', 'page')
            ->set('form.items.0.children.0.children.0.page_id', $guide->id)
            ->set('form.items.0.children.0.children.0.label', 'Košulje')
            ->call('addChildItem', '0', 'custom')
            ->set('form.items.0.children.1.label', 'Cjenik')
            ->set('form.items.0.children.1.url', '/cjenik')
            ->call('moveNestedItem', '0.children.1', -1)
            ->call('save')->assertHasNoErrors();

        $items = app(NavigationMenuService::class)->configuredItems();
        $this->assertSame(['Cjenik', 'Usluge'], array_column($items[0]['children'], 'label'));
        $this->assertSame($guide->id, $items[0]['children'][1]['children'][0]['page_id']);
        $component->set('locale', 'en')
            ->set('form.items.0.children.1.children.0.label', 'Shirts')
            ->call('save')->assertHasNoErrors();
        $translations = app(NavigationMenuService::class)->configuredItems()[0]['children'][1]['children'][0]['label_translations'];
        $this->assertSame(['hr' => 'Košulje', 'en' => 'Shirts'], $translations);
        $component->call('removeNestedItem', '0.children.0')->call('save')->assertHasNoErrors();
        $this->assertSame(['Usluge'], array_column(app(NavigationMenuService::class)->configuredItems()[0]['children'], 'label'));
    }

    public function test_invalid_nested_page_does_not_overwrite_saved_navigation(): void
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);
        $this->settings([['type' => 'custom', 'label' => 'Praonica', 'url' => '/praonica']]);
        $before = app(NavigationMenuService::class)->configuredItems();
        Livewire::actingAs($user)->test(Manager::class)
            ->call('addChildItem', '0', 'page')
            ->call('save')->assertHasErrors(['form.items.0.children.0.page_id']);
        $this->assertSame($before, app(NavigationMenuService::class)->configuredItems());
    }

    public function test_imported_navigation_seeder_builds_requested_hierarchy_and_footer_without_duplicates(): void
    {
        foreach ([68, 69, 92, 154, 155, 73, 265, 157, 156, 159, 246, 268, 158, 311, 75, 168, 163, 178, 164, 179, 180, 76, 169, 170, 267, 359, 70, 87, 88, 322, 71, 174, 78, 85, 153] as $sourceId) {
            $record = $this->page('Uvezena stranica '.$sourceId, $sourceId === 68 ? 'usluge' : 'import-page-'.$sourceId);
            $record->update(['code' => 'lavaggio-modx-'.$sourceId]);
        }
        $category = Category::create(['scope' => 'catalog', 'code' => 'detergents', 'is_active' => true, 'show_in_menu' => true]);
        $category->translations()->create(['scope' => 'catalog', 'locale' => 'hr', 'name' => 'Deterdženti', 'slug' => 'deterdzenti']);

        $this->seed(LavaggioContentNavigationSeeder::class);
        $service = app(NavigationMenuService::class);
        $first = $service->storefrontItemsForLocale('hr');
        $this->seed(LavaggioContentNavigationSeeder::class);
        $this->assertSame($first, $service->storefrontItemsForLocale('hr'));
        $this->assertSame(['Web shop', 'Praonica', 'Pitanja i odgovori', 'TV studio', 'Tko smo mi', 'Kontakt'], array_column($first, 'label'));
        $groups = $first[1]['children'];
        $this->assertSame([
            'Samoposlužna praonica rublja', 'Uslužna praonica rublja', 'Prikup i dostava rublja',
            'Ozonsko pranje rublja', 'Što peremo u našoj praonici', 'Rezervacije 2027', 'Cjenik', 'O praonici',
        ], array_column($groups, 'label'));
        $this->assertSame([3, 8, 6, 2, 0, 0, 3, 3], array_map(fn (array $item): int => count($item['children']), $groups));
        $this->assertSame('Pranje i održavanje stolnog i kuhinjskog rublja za restorane', $groups[1]['children'][7]['label']);
        $this->assertSame(['Barban', 'Žminj', 'Marčana', 'Kanfanar', 'Svetvinčenat', 'Vodnjan'], array_column($groups[2]['children'], 'label'));
        $this->assertSame(route('pages.show', 'import-page-311'), $groups[1]['children'][7]['url']);

        $footer = app(StoreSettingsService::class)->footer()['link_columns'];
        $this->assertSame('Web shop', $footer[0]['title']);
        $this->assertSame(['Deterdženti'], array_column($footer[0]['links'], 'label'));
        $this->assertSame('Informacije', $footer[1]['title']);
        $this->assertSame(array_column(array_slice($first, 1), 'label'), array_column($footer[1]['links'], 'label'));
        $this->assertSame(array_column(array_slice($first, 1), 'url'), array_column($footer[1]['links'], 'url'));

        $response = $this->get(route('home'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(2, $xpath->query('//*[@data-lavaggio-catalog-trigger]')->length);
        $this->assertSame(8, $xpath->query('//*[@data-lavaggio-catalog-menu][not(@id="lavaggio-web-shop-menu")]//a[@data-lavaggio-catalog-root]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-mobile-menu-panel]//a[@href="'.route('pages.show', 'import-page-311').'"]')->length);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_missing_imported_page_keeps_existing_navigation_settings_intact(): void
    {
        $before = app(NavigationMenuService::class)->configuredItems();
        try {
            $this->seed(LavaggioContentNavigationSeeder::class);
            $this->fail('Seeding without imported content must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Najprije uvezite MODX stranicu', $exception->getMessage());
        }
        $this->assertSame($before, app(NavigationMenuService::class)->configuredItems());
    }

    private function page(string $title, string $slug): InfoPage
    {
        $page = InfoPage::create(['code' => $slug, 'layout' => 'default', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'title' => $title, 'slug' => $slug]);

        return $page;
    }

    private function settings(array $items): void
    {
        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY, $items);
    }
}
