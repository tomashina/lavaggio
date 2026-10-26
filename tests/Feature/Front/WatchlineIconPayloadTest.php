<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Services\Front\WatchlineHeroService;
use App\Services\Settings\SystemSettingsService;
use App\Support\FontAwesomeIcon;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WatchlineIconPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_home_category_and_product_icons_never_request_full_library_sprites(): void
    {
        $this->app['env'] = 'local';
        config([
            'app.name' => 'Watchline', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.theme' => 'herrera', 'storefront.home' => 'watchline', 'storefront.brand' => 'libermedia',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false,
            'stock_notifications.enabled' => true,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
            'database.connections.watchline_source.host' => 'source-must-not-be-used.invalid',
        ]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Watchline', 'store_announcement_enabled' => false,
        ]);

        $category = Category::create(['code' => 'satovi', 'scope' => 'catalog', 'is_active' => true, 'show_in_menu' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Satovi', 'slug' => 'satovi']);
        foreach (['naocale' => 'Naočale', 'nakit' => 'Nakit'] as $slug => $name) {
            $root = Category::create(['code' => $slug, 'scope' => 'catalog', 'is_active' => true, 'show_in_menu' => true]);
            $root->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => $name, 'slug' => $slug]);
        }
        $child = Category::create(['code' => 'zenski-satovi', 'scope' => 'catalog', 'is_active' => true, 'show_in_menu' => true, 'parent_id' => $category->id]);
        $child->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Ženski satovi', 'slug' => 'zenski-satovi']);
        $manufacturer = Manufacturer::create(['code' => 'cluse', 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'slug' => 'cluse', 'name' => 'CLUSE']);
        $product = Product::create([
            'code' => 'icon-fixture', 'sku' => 'ICON-FIXTURE', 'is_active' => true, 'stock_qty' => 3,
            'base_price' => 100, 'manufacturer_id' => $manufacturer->id,
            'payload' => ['opencart' => ['product_id' => 1, 'image' => 'catalog/watch.jpg']],
        ]);
        $product->translations()->create(['locale' => 'hr', 'slug' => 'icon-fixture', 'name' => 'CLUSE sat']);
        $product->categories()->attach($child);
        $product->comments()->create([
            'locale' => 'hr', 'author_name' => 'Kupac', 'body' => 'Iskustvo kupca.',
            'rating' => 5, 'status' => Comment::STATUS_APPROVED,
        ]);
        $hero = $this->mock(WatchlineHeroService::class);
        $hero->shouldReceive('products')->andReturn(new Collection([$product]));
        $hero->shouldReceive('rankedProductIds')->andReturn(collect([$product->id]));
        $this->artisan('watchline:configure-preview', ['--navigation-only' => true])->assertSuccessful();
        $this->artisan('watchline:configure-home')->assertSuccessful();

        $home = $this->get('/')->assertOk();
        $home->assertSee('data-watchline-locations', false)->assertSee('data-home-review-rating="5"', false)
            ->assertSee('data-watchline-category-toggle', false);
        $productPage = $this->get('/product/icon-fixture')->assertOk()->assertSee('data-stock-notify-trigger', false);

        foreach ([$home, $this->get('/category/satovi')->assertOk(), $productPage] as $response) {
            $response->assertDontSee('/fontawesome-pro-7.3.1/sprites/', false);
            $response->assertSee('/fontawesome-pro-7.3.1/storefront-sprites/', false);
        }
    }

    public function test_configurable_watchline_icon_defaults_and_allowed_navigation_icons_use_small_subsets(): void
    {
        $renderedStyles = [
            'watchline_campaigns' => ['arrow_icon' => 'solid', 'perk_icon' => 'solid'],
            'watchline_locations' => ['location_icon' => 'light', 'hours_icon' => 'light', 'link_icon' => 'solid'],
            'watchline_assurance' => ['link_icon' => 'solid', 'point_1_icon' => 'regular', 'point_2_icon' => 'regular', 'point_3_icon' => 'regular'],
            'watchline_benefits' => ['item_1_icon' => 'regular', 'item_2_icon' => 'regular', 'item_3_icon' => 'regular', 'item_4_icon' => 'regular'],
        ];

        foreach ($renderedStyles as $type => $fields) {
            $settings = WatchlineWidgetSettings::defaults($type);
            foreach ($fields as $field => $style) {
                $this->assertStringContainsString('/storefront-sprites/', FontAwesomeIcon::url($settings[$field], $style), $type.'.'.$field);
            }
        }
        foreach (['gift', 'ticket'] as $name) {
            $this->assertNotNull(FontAwesomeIcon::inline($name, 'regular'));
        }

        $this->assertNull(FontAwesomeIcon::inline('hand-fingers-crossed', 'duotone'));
        $this->assertStringContainsString('/sprites/duotone.svg', FontAwesomeIcon::url('hand-fingers-crossed', 'duotone'));
    }
}
