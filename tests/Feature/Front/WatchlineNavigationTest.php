<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchlineNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_categories_and_complete_mobile_drawer_keep_accessible_navigation(): void
    {
        $this->configureNavigation();
        $response = $this->get('/')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);

        foreach (['desktop'] as $surface) {
            $links = $xpath->query('//*[@data-watchline-category-nav="'.$surface.'"]//*[@data-watchline-category-link]');
            $this->assertSame(['Satovi', 'Naočale', 'Nakit'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($links)));
            $toggles = $xpath->query('//*[@data-watchline-category-nav="'.$surface.'"]//*[@data-watchline-category-toggle]');
            $this->assertCount(3, $toggles);
            foreach ($toggles as $toggle) {
                $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
                $panels = $xpath->query('//*[@id="'.$toggle->getAttribute('aria-controls').'"]');
                $this->assertCount(1, $panels);
                $this->assertTrue($panels->item(0)->hasAttribute('hidden'));
            }
        }
        $this->assertCount(0, $xpath->query('//*[@data-watchline-category-nav="mobile"]'));
        $this->assertCount(0, $xpath->query('//*[contains(@class,"watchline-mobile-category-row")]'));
        $drawerText = $xpath->query('//*[@data-mobile-menu-panel]')->item(0)->textContent;
        foreach (['Satovi', 'Naočale', 'Nakit', 'Ženski satovi', 'Muške naočale', 'Ogrlice', 'Brandovi', 'Akcije', 'Blog', 'O nama', 'Kontakt', 'Tražiš poklon?', 'Poklon bon'] as $label) {
            $this->assertStringContainsString($label, $drawerText);
        }
        foreach (['//*[@data-watchline-category-nav="desktop"]', '//*[@data-mobile-menu-panel]'] as $surface) {
            $this->assertCount(1, $xpath->query($surface.'//a[@href="'.route('categories.show', ['slug' => 'zenski-satovi']).'"]'));
            $this->assertCount(0, $xpath->query($surface.'//a[@href="'.route('categories.show', ['slug' => 'zenski-casio']).'"]'));
        }
        $this->assertCount(0, $xpath->query('//*[@data-watchline-category-depth="1"]'));
        $this->assertCount(0, $xpath->query('//*[@data-mobile-menu-panel]//*[@data-mobile-menu-accordion]//*[@data-mobile-menu-accordion]'));
        $headerText = $xpath->query('//header[contains(@class,"site-main-header")]')->item(0)->textContent;
        $this->assertStringNotContainsString('Sve kategorije', $headerText);
        $response->assertSeeInOrder(['Brandovi', 'Akcije', 'Blog', 'O nama', 'Kontakt', 'Tražiš poklon?', 'Poklon bon'])
            ->assertSee('Ženski satovi')->assertSee('Muške naočale')->assertSee('Ogrlice');
        foreach (['//*[@data-herrera-primary-navigation]', '//*[@data-mobile-menu-panel]'] as $surface) {
            $guide = $xpath->query($surface.'//a[@href="'.route('gift-guide.index', absolute: false).'"]');
            $this->assertCount(1, $guide);
            $this->assertStringContainsString('Tražiš poklon?', $guide->item(0)->textContent);
            $this->assertGreaterThan(0, $xpath->query('.//svg', $guide->item(0))->count());
        }
        $this->assertSame(1, substr_count($response->getContent(), 'scripts/watchline-category-navigation.js'));
    }

    public function test_watchline_search_stays_persistent_with_original_autocomplete_form_and_solid_header_icons(): void
    {
        $this->configureNavigation();
        app(SystemSettingsService::class)->put('store_search_autocomplete_enabled', true);
        $response = $this->get('/?q=cluse')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $panel = $xpath->query('//*[@data-header-search-panel]')->item(0);
        $form = $xpath->query('//*[@data-header-search-form]')->item(0);

        $this->assertSame('0', $panel->getAttribute('data-header-search-breakpoint'));
        $this->assertTrue($panel->hasAttribute('data-header-search-persistent'));
        $this->assertFalse($panel->hasAttribute('data-header-search-fullscreen'));
        $this->assertStringContainsString('watchline-header-search-panel', $panel->getAttribute('class'));
        $this->assertStringContainsString('watchline-header-search', $form->getAttribute('class'));
        $this->assertSame('GET', $form->getAttribute('method'));
        $this->assertSame(route('shop.index'), $form->getAttribute('action'));
        $this->assertSame('1', $form->getAttribute('data-autocomplete-enabled'));
        $this->assertSame(route('search.autocomplete'), $form->getAttribute('data-autocomplete-endpoint'));
        $this->assertSame('cluse', $xpath->query('//*[@data-header-search-input]')->item(0)->getAttribute('value'));
        $this->assertCount(0, $xpath->query('//*[@data-header-search-close or @data-header-search-toggle]'));
        $this->assertCount(1, $xpath->query('//*[@data-mobile-menu-open]'));
        foreach (['user', 'bag-shopping', 'heart', 'bars-staggered', 'magnifying-glass'] as $name) {
            $this->assertStringContainsString(\App\Support\FontAwesomeIcon::inline($name, 'solid')['content'] ?? '#'.$name, $response->getContent());
        }
    }

    public function test_navigation_only_configuration_requires_no_source_connection_and_preserves_cms_content(): void
    {
        $this->configureNavigation();
        $items = app(NavigationMenuService::class)->primaryCategoryItems('hr');

        $this->assertSame(['Satovi', 'Naočale', 'Nakit'], array_column($items, 'label'));
        $this->assertSame('Ženski satovi', $items[0]['children'][0]['label']);
        $this->assertSame([], $items[0]['children'][0]['children']);
        $this->assertDatabaseCount('content_blocks', 0);
        $appearance = app(NavigationMenuService::class)->appearance();
        $this->assertSame('#0077b5', strtolower($appearance['background_color']));
        $this->assertSame('#0093dd', strtolower($appearance['highlight_color']));
        $this->assertSame('#EDF6FC', app(SystemSettingsService::class)->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY)['background_color']);
        $this->assertFalse(collect(app(NavigationMenuService::class)->configuredItems())->contains('type', 'catalog'));
    }

    public function test_gift_guide_addition_is_idempotent_and_preserves_every_existing_custom_navigation_value(): void
    {
        $existing = [
            ['type' => 'custom', 'label' => 'Moj link', 'url' => '/moja-stranica?odabir=1', 'sort_order' => 3,
                'is_active' => false, 'desktop_promo_title' => 'Zadržati sadržaj', 'label_translations' => ['hr' => 'Moj link', 'en' => 'My link']],
            ['type' => 'custom', 'label' => 'Poklon bon', 'url' => route('gift-vouchers.create', absolute: false),
                'icon' => 'gift', 'align_right' => true, 'is_active' => true, 'sort_order' => 11],
            ['type' => 'custom', 'label' => 'Zadnja stavka', 'url' => 'https://example.com/original', 'sort_order' => 25],
        ];
        $updated = \App\Support\WatchlineGiftGuideNavigation::addTo($existing);
        $this->assertSame('Tražiš poklon?', $updated[1]['label']);
        $this->assertSame(route('gift-guide.index', absolute: false), $updated[1]['url']);
        $this->assertSame('gift', $updated[1]['icon']);
        $this->assertTrue($updated[1]['align_right']);
        $this->assertSame(11, $updated[1]['sort_order']);
        $withoutGuide = $updated;
        array_splice($withoutGuide, 1, 1);
        $this->assertSame($existing, $withoutGuide);
        $this->assertSame($updated, \App\Support\WatchlineGiftGuideNavigation::addTo($updated));
    }

    public function test_watchline_menu_depth_preserves_catalog_hierarchy_and_other_storefront_navigation(): void
    {
        $this->configureNavigation();
        $service = app(NavigationMenuService::class);
        $this->assertSame([], $service->primaryCategoryItems('hr')[0]['children'][0]['children']);
        $women = Category::query()->where('code', 'zenski-satovi')->firstOrFail();
        $this->assertDatabaseHas('categories', ['code' => 'zenski-casio', 'parent_id' => $women->id]);

        config(['storefront.home' => 'auto']);
        $this->assertSame('Casio', $service->primaryCategoryItems('hr')[0]['children'][0]['children'][0]['label']);
        config(['storefront.home' => 'watchline']);
        $this->assertSame([], $service->primaryCategoryItems('hr')[0]['children'][0]['children']);
    }

    public function test_persistent_search_retains_catalog_filters_in_its_native_get_submission(): void
    {
        $this->configureNavigation();
        $parameters = ['q' => 'cluse', 'category' => 'satovi', 'manufacturer' => 'cluse', 'size' => 'M', 'sort' => 'price_asc', 'cols' => '4'];
        $response = $this->get('/shop?'.http_build_query($parameters))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        foreach ($parameters as $name => $value) {
            $inputs = $xpath->query('//*[@data-header-search-form]//input[@name="'.$name.'"]');
            $this->assertCount(1, $inputs);
            $this->assertSame($value, $inputs->item(0)->getAttribute('value'));
        }
    }

    public function test_hidden_third_level_categories_keep_their_direct_urls_products_and_parent_product_scope(): void
    {
        $this->configureNavigation();
        $women = Category::query()->where('code', 'zenski-satovi')->firstOrFail();
        $thirdLevel = Category::query()->where('code', 'zenski-casio')->firstOrFail();
        $product = Product::create(['code' => 'deep-category-watch', 'is_active' => true, 'stock_qty' => 3, 'base_price' => 99]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sat iz izvorne potkategorije', 'slug' => 'deep-category-watch']);
        $product->categories()->attach($thirdLevel);

        $this->get(route('categories.show', ['slug' => 'satovi']))->assertOk()
            ->assertViewHas('subcategoryCards', fn ($categories) => $categories->pluck('id')->all() === [$women->id]);
        $response = $this->get(route('categories.show', ['slug' => 'zenski-satovi']))->assertOk()
            ->assertViewHas('subcategories', fn ($categories) => $categories->isEmpty())
            ->assertViewHas('subcategoryCards', fn ($categories) => $categories->isEmpty())
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$product->id]);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        foreach (['catalog-sidebar-category-options', 'catalog-mobile-filter-options'] as $surface) {
            $this->assertCount(0, $xpath->query('//*[contains(@class,"'.$surface.'")]//a[@href="'.route('categories.show', ['slug' => 'zenski-casio']).'"]'));
        }
        $thirdLevelResponse = $this->get(route('categories.show', ['slug' => 'zenski-casio']))->assertOk()
            ->assertViewHas('category', fn ($category) => $category->id === $thirdLevel->id)
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$product->id]);
        $productResponse = $this->get(route('products.show', ['slug' => 'deep-category-watch']))->assertOk()
            ->assertViewHas('productBreadcrumbCategories', fn ($categories) => $categories->contains('id', $thirdLevel->id));
        foreach ([$thirdLevelResponse, $productResponse] as $breadcrumbResponse) {
            $document = new \DOMDocument;
            @$document->loadHTML('<?xml encoding="UTF-8">'.$breadcrumbResponse->getContent());
            $xpath = new \DOMXPath($document);
            $this->assertCount(0, $xpath->query('//nav[@aria-label="Breadcrumb"]//a[@href="'.route('categories.show', ['slug' => 'zenski-casio']).'"]'));
            $this->assertCount(1, $xpath->query('//nav[@aria-label="Breadcrumb"]//a[@href="'.route('categories.show', ['slug' => 'zenski-satovi']).'"]'));
            $this->assertStringNotContainsString('Casio', $xpath->query('//nav[@aria-label="Breadcrumb"]')->item(0)->textContent);
        }
        $this->assertStringContainsString('Casio', $thirdLevelResponse->getContent());
        $this->assertDatabaseHas('categories', ['id' => $thirdLevel->id, 'parent_id' => $women->id, 'is_active' => true]);

        app(SystemSettingsService::class)->put('store_search_autocomplete_enabled', true);
        $this->getJson(route('search.autocomplete', ['q' => 'Casio']))->assertOk()
            ->assertJsonPath('groups.categories.total', 0);
        config(['storefront.home' => 'auto']);
        $this->get(route('categories.show', ['slug' => 'zenski-satovi']))->assertOk()
            ->assertViewHas('subcategories', fn ($categories) => $categories->pluck('id')->all() === [$thirdLevel->id]);
        $legacyResponse = $this->get(route('products.show', ['slug' => 'deep-category-watch']))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$legacyResponse->getContent());
        $this->assertCount(1, (new \DOMXPath($document))->query('//nav[@aria-label="Breadcrumb"]//a[@href="'.route('categories.show', ['slug' => 'zenski-casio']).'"]'));
    }

    private function configureNavigation(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.name' => 'Watchline', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.home' => 'watchline', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false,
            'database.connections.watchline_source.host' => 'source-connection-must-not-be-used.invalid',
        ]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Watchline');
        $watches = $this->category('satovi', 'Satovi');
        $women = $this->category('zenski-satovi', 'Ženski satovi', $watches);
        $this->category('zenski-casio', 'Casio', $women);
        $glasses = $this->category('naocale', 'Sunčane naočale');
        $this->category('muske-naocale', 'Muške naočale', $glasses);
        $jewelry = $this->category('nakit', 'Nakit');
        $this->category('ogrlice', 'Ogrlice', $jewelry);
        $page = InfoPage::create(['code' => 'about', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'title' => 'O nama', 'slug' => 'o-nama']);
        $article = BlogPost::create(['code' => 'article', 'is_active' => true, 'published_at' => now()->subDay()]);
        $article->translations()->create(['locale' => 'hr', 'title' => 'Novosti', 'slug' => 'novosti']);
        $this->artisan('watchline:configure-preview', ['--navigation-only' => true])->assertSuccessful();
    }

    private function category(string $slug, string $name, ?Category $parent = null): Category
    {
        $category = Category::create(['code' => $slug, 'scope' => 'catalog', 'is_active' => true, 'show_in_menu' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => $name, 'slug' => $slug]);

        return $category;
    }
}
