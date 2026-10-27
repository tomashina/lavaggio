<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Services\Front\NavigationMenuService;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioDynamicCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'app.locale' => 'hr',
            'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia',
            'storefront_cache.enabled' => true,
        ]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            NavigationMenuService::SETTINGS_KEY => [
                ['type' => 'contact', 'label' => 'Kontakt'],
            ],
            'store_footer_col_1_category_ids' => [],
        ]);
    }

    public function test_category_add_rename_and_deactivation_update_navigation_and_footer_without_products(): void
    {
        $navigation = app(NavigationMenuService::class);
        $store = app(StoreSettingsService::class);

        $this->assertSame([], $navigation->primaryCategoryItems('hr'));
        $this->assertSame([], $store->footer()['link_columns'][0]['links']);

        $root = $this->category('Pranje', 'pranje');
        $child = $this->category('Pribor', 'pribor', $root);
        $grandchild = $this->category('Četke', 'cetke', $child);
        $this->category('Meke četke', 'meke-cetke', $grandchild);
        $otherRoot = $this->category('Održavanje', 'odrzavanje', attributes: ['sort_order' => -1]);

        $items = $navigation->primaryCategoryItems('hr');
        $this->assertSame(['Održavanje', 'Pranje'], array_column($items, 'label'));
        $this->assertSame('Pribor', $items[1]['children'][0]['label']);
        $this->assertSame('Četke', $items[1]['children'][0]['children'][0]['label']);
        $this->assertSame('Meke četke', $items[1]['children'][0]['children'][0]['children'][0]['label']);
        $this->assertSame(['Održavanje', 'Pranje'], array_column($store->footer()['link_columns'][0]['links'], 'label'));
        $this->assertDatabaseCount('products', 0);

        $root->translations()->where('locale', 'hr')->update(['name' => 'Pranje vozila', 'slug' => 'pranje-vozila']);
        $items = $navigation->primaryCategoryItems('hr');
        $this->assertSame('Pranje vozila', $items[1]['label']);
        $this->assertSame(route('categories.show', ['slug' => 'pranje-vozila']), $items[1]['url']);
        $footer = $store->footer()['link_columns'][0]['links'];
        $this->assertSame('Pranje vozila', $footer[1]['label']);
        $this->assertSame($items[1]['url'], $footer[1]['url']);

        $root->update(['is_active' => false]);
        $this->assertSame(['Održavanje'], array_column($navigation->primaryCategoryItems('hr'), 'label'));
        $this->assertSame(['Održavanje'], array_column($store->footer()['link_columns'][0]['links'], 'label'));

        $otherRoot->delete();
        $this->assertSame([], $navigation->primaryCategoryItems('hr'));
        $this->assertSame([], $store->footer()['link_columns'][0]['links']);
    }

    public function test_categories_honor_scope_schedules_visibility_and_localized_names(): void
    {
        $this->travelTo(now()->startOfMinute());
        $root = $this->category('Oprema', 'oprema');
        $root->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'en', 'name' => 'Equipment', 'slug' => 'equipment']);
        $this->category('Aktivni pribor', 'aktivni-pribor', $root);
        $hiddenChild = $this->category('Skriveni pribor', 'skriveni-pribor', $root, ['show_in_menu' => false]);
        $this->category('Unuk skrivene kategorije', 'skriveni-unuk', $hiddenChild);
        $this->category('Neaktivno', 'neaktivno', attributes: ['is_active' => false]);
        $this->category('Skriveno', 'skriveno', attributes: ['show_in_menu' => false]);
        $this->category('Uskoro', 'uskoro', attributes: ['starts_at' => now()->addMinute()]);
        $this->category('Završeno', 'zavrseno', attributes: ['ends_at' => now()->subMinute()]);
        $this->category('Blog kategorija', 'blog-kategorija', attributes: ['scope' => Category::SCOPE_BLOG]);

        $navigation = app(NavigationMenuService::class);
        $items = $navigation->primaryCategoryItems('en');
        $this->assertSame(['Equipment'], array_column($items, 'label'));
        $this->assertSame(route('categories.show', ['slug' => 'equipment']), $items[0]['url']);
        $this->assertSame(['Aktivni pribor'], array_column($items[0]['children'], 'label'));
        $this->assertSame(['Oprema'], array_column(app(StoreSettingsService::class)->footer()['link_columns'][0]['links'], 'label'));

        $this->travel(2)->minutes();
        $this->assertSame(['Oprema', 'Uskoro'], array_column($navigation->primaryCategoryItems('hr'), 'label'));
        $this->assertSame(['Oprema', 'Uskoro'], array_column(app(StoreSettingsService::class)->footer()['link_columns'][0]['links'], 'label'));
    }

    public function test_stored_category_and_catalog_entries_do_not_duplicate_or_override_automatic_roots(): void
    {
        $root = $this->category('Proizvodi za njegu', 'proizvodi-za-njegu');
        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY, [
            ['type' => 'category', 'category_id' => $root->id, 'label' => 'Stari naziv'],
            ['type' => 'catalog', 'label' => 'Stari katalog'],
            ['type' => 'contact', 'label' => 'Kontakt'],
        ]);
        app(SystemSettingsService::class)->put('store_footer_col_1_category_ids', [$root->id, $root->id]);

        $this->assertSame(['Proizvodi za njegu', 'Kontakt'], array_column(app(NavigationMenuService::class)->forLocale('hr'), 'label'));
        $this->assertSame(['Proizvodi za njegu'], array_column(app(StoreSettingsService::class)->footer()['link_columns'][0]['links'], 'label'));

        config()->set('storefront.home', 'watchline');
        $this->assertSame(['Stari naziv', 'Stari katalog', 'Kontakt'], array_column(app(NavigationMenuService::class)->forLocale('hr'), 'label'));
    }

    private function category(string $name, string $slug, ?Category $parent = null, array $attributes = []): Category
    {
        $category = Category::create(array_merge([
            'scope' => Category::SCOPE_CATALOG,
            'code' => $slug,
            'is_active' => true,
            'show_in_menu' => true,
            'parent_id' => $parent?->id,
        ], $attributes));
        $category->translations()->create([
            'scope' => $category->scope,
            'locale' => 'hr',
            'name' => $name,
            'slug' => $slug,
        ]);

        return $category;
    }
}
