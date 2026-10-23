<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Settings\SystemSettingsService;
use App\Support\StorefrontTheme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontThemeSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_theme_retains_liber_media_branding_and_public_purchasing(): void
    {
        config(['storefront.theme' => 'herrera', 'storefront.home' => 'libermedia', 'commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Liber Media',
            'store_brand_logo_path' => 'assets/brand/libermedia-logo.svg',
            'store_brand_logo_dark_path' => 'assets/brand/libermedia-logo-dark.svg',
            'store_pricing_b2c_enabled' => true,
        ]);

        foreach (['/', '/shop', '/auth/login'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee('storefront herrera-storefront', false)
                ->assertSee('herrera-b2b.css', false)
                ->assertSee('herrera-typography.css', false)
                ->assertSee('libermedia-storefront', false)
                ->assertSee('libermedia.css', false)
                ->assertSee('/assets/brand/libermedia-logo.svg', false)
                ->assertSee('data-herrera-header', false)
                ->assertSee('Liber Media')
                ->assertSee('Liber Media novosti')
                ->assertDontSee('Herrera novosti')
                ->assertDontSee('data-herrera-quick-order', false)
                ->assertDontSee('data-herrera-home-registration', false)
                ->assertDontSee('/assets/brand/herrera-logo', false)
                ->assertDontSee(route('supplier-catalogs.index'), false)
                ->assertDontSee(route('references.index'), false);
        }

        $this->get('/')->assertOk()->assertSee('data-libermedia-home-hero', false)
            ->assertSee('/assets/brand/libermedia-logo-dark.svg', false)
            ->assertSee('data-store-brand-logo-dark', false)
            ->assertDontSee('data-herrera-category-support', false);
        $this->assertFalse(config('commerce.b2b_only'));
    }

    public function test_auto_keeps_the_original_brand_based_layout_choice(): void
    {
        config(['storefront.theme' => 'auto']);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->assertTrue(StorefrontTheme::usesHerreraLayout());
        $this->get('/shop')->assertOk()->assertSee('herrera-b2b.css', false);

        app(SystemSettingsService::class)->put('store_brand_name', 'Liber Media');
        $this->assertFalse(StorefrontTheme::usesHerreraLayout());
        $this->assertFalse(StorefrontTheme::usesHerreraLayout('Liber Media'));
    }

    public function test_managed_brand_blocks_do_not_append_herrera_business_registration_to_liber_media(): void
    {
        config(['storefront.theme' => 'herrera', 'storefront.home' => 'libermedia']);
        app(SystemSettingsService::class)->put('store_brand_name', 'Liber Media');
        $block = ContentBlock::query()->create([
            'code' => 'liber-brands', 'name' => 'Liber brands', 'type' => 'popular_brands', 'is_active' => true,
        ]);
        $block->translations()->create(['locale' => 'hr', 'title' => 'Liber brands']);
        $block->slots()->create(['placement' => 'home.after_products', 'frontend_variant' => 'desktop', 'is_active' => true]);

        $this->get('/')->assertOk()->assertSee('Liber brands')
            ->assertDontSee('data-herrera-home-registration', false);
    }

    public function test_an_explicit_other_theme_disables_the_legacy_herrera_name_trigger(): void
    {
        config(['storefront.theme' => 'default']);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');

        $this->get('/shop')->assertOk()->assertDontSee('herrera-b2b.css', false)
            ->assertDontSee('data-herrera-header', false);
    }

    public function test_liber_media_category_pages_receive_subcategory_cards_with_the_explicit_theme(): void
    {
        config(['storefront.theme' => 'herrera', 'storefront.home' => 'libermedia']);
        app(SystemSettingsService::class)->put('store_brand_name', 'Liber Media');
        $root = $this->category('liber-root');
        $child = $this->category('liber-child', $root);
        $product = Product::query()->create(['code' => 'liber-product', 'is_active' => true, 'stock_qty' => 1, 'base_price' => 10]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Liber product', 'slug' => 'liber-product']);
        $product->categories()->attach($child);

        $this->get(route('categories.show', ['slug' => 'liber-root']))->assertOk()
            ->assertSee('data-subcategory-card="'.$child->id.'"', false)
            ->assertViewHas('subcategoryCards', fn ($cards) => $cards->modelKeys() === [$child->id]);
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::query()->create([
            'code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true, 'parent_id' => $parent?->id,
        ]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => $slug, 'slug' => $slug]);

        return $category;
    }
}
