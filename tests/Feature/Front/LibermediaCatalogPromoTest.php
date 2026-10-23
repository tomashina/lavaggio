<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibermediaCatalogPromoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        config(['storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'storefront.home' => 'libermedia', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false, 'gift_vouchers.enabled' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Liber Media', 'store_pricing_b2c_enabled' => true,
            'store_product_desktop_default_cols' => 5, 'front_category_products_per_page_desktop' => 24,
        ]);
    }

    public function test_promo_templates_leave_the_product_count_order_and_pagination_unchanged(): void
    {
        $this->products(25);
        $response = $this->get(route('shop.index'))->assertOk()
            ->assertSee('data-libermedia-catalog-promos', false)
            ->assertSee('data-catalog-promo-template="bundles"', false)
            ->assertSee('data-catalog-promo-template="gift-voucher"', false)
            ->assertSee('libermedia-catalog-promos.js', false)
            ->assertSee('libermedia-catalog-promos.css', false);
        $products = $response->viewData('products');
        $this->assertCount(24, $products);
        $this->assertSame(25, $products->total());
        $this->assertNotNull($products->nextPageUrl());
        $xpath = $this->xpath($response->getContent());
        $cards = $xpath->query('//*[@data-catalog-grid]/*[@data-product-card]');
        $this->assertSame(24, $cards->length);
        $this->assertSame($products->pluck('id')->map(fn ($id) => (string) $id)->all(), array_map(fn ($card) => $card->getAttribute('data-product-id'), iterator_to_array($cards)));
        $this->assertSame(0, $xpath->query('//*[@data-catalog-grid]//*[@data-catalog-promo-template]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-catalog-grid]/*[@data-catalog-promo-card]')->length);
        $this->assertSame(route('bundles.index'), $xpath->query('//template[@data-catalog-promo-template="bundles"]/a')->item(0)->getAttribute('href'));
        $this->assertSame(route('gift-vouchers.create'), $xpath->query('//template[@data-catalog-promo-template="gift-voucher"]/a')->item(0)->getAttribute('href'));
    }

    public function test_load_more_responses_keep_templates_outside_the_appendable_grid(): void
    {
        app(SystemSettingsService::class)->put('store_product_catalog_pagination_mode', 'load_more');
        $this->products(25);
        $first = $this->get(route('shop.index'))->assertOk()->assertSee('data-catalog-load-more-root', false);
        $this->assertSame(24, $this->xpath($first->getContent())->query('//*[@data-catalog-grid]/*')->length);
        $next = $this->withHeader('X-Requested-With', 'XMLHttpRequest')->get(route('shop.index', ['page' => 2]))
            ->assertOk()->assertSee('data-libermedia-catalog-promos', false);
        $this->assertCount(1, $next->viewData('products'));
        $this->assertSame(25, $next->viewData('products')->total());
        $this->assertSame(1, $this->xpath($next->getContent())->query('//*[@data-catalog-grid]/*')->length);
        $this->assertSame(2, $this->xpath($next->getContent())->query('//template[@data-catalog-promo-template]')->length);
    }

    public function test_disabled_gift_vouchers_are_not_promoted(): void
    {
        config(['gift_vouchers.enabled' => false]);
        $this->products(1);
        $this->get(route('shop.index'))->assertOk()->assertSee('data-catalog-promo-template="bundles"', false)
            ->assertDontSee('data-catalog-promo-template="gift-voucher"', false);
    }

    public function test_other_storefront_brands_do_not_get_libermedia_promo_tiles(): void
    {
        config(['storefront.brand' => 'auto']);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->products(1);
        $this->get(route('shop.index'))->assertOk()->assertDontSee('data-libermedia-catalog-promos', false)
            ->assertDontSee('libermedia-catalog-promos.js', false)
            ->assertDontSee('libermedia-catalog-promos.css', false);
    }

    public function test_watchline_catalog_category_and_brand_promotions_only_offer_the_gift_voucher(): void
    {
        config(['storefront.home' => 'watchline', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr']);
        app(SystemSettingsService::class)->put('catalog_use_manufacturers', true);
        $category = Category::create(['code' => 'watchline-promo-watches', 'scope' => 'catalog', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Satovi', 'slug' => 'watchline-promo-watches']);
        $manufacturer = Manufacturer::create(['code' => 'watchline-promo-brand', 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => 'Brand satova', 'slug' => 'watchline-promo-brand']);
        $this->products(4);
        foreach (Product::all() as $product) {
            $product->categories()->attach($category);
            $product->update(['manufacturer_id' => $manufacturer->id]);
        }

        foreach ([route('shop.index'), route('categories.show', ['slug' => 'watchline-promo-watches']), route('manufacturers.show', ['slug' => 'watchline-promo-brand'])] as $url) {
            $response = $this->get($url);
            $this->assertSame(200, $response->status(), $url);
            $response->assertSee('Poklon bon')
                ->assertSee('Darujte izbor satova, naočala i nakita.')
                ->assertDontSee('Kupi u kompletu i uštedi')->assertDontSee('Darujte igru i veselje.');
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(1, $xpath->query('//template[@data-catalog-promo-template]')->length);
            $this->assertSame(0, $xpath->query('//template[@data-catalog-promo-template="bundles"]')->length);
            $this->assertSame(route('gift-vouchers.create'), $xpath->query('//template[@data-catalog-promo-template="gift-voucher"]/a')->item(0)->getAttribute('href'));
            $this->assertCount(4, $response->viewData('products'));
        }

        $directory = $this->get(route('categories.index'))->assertOk()
            ->assertSee('data-watchline-category-gift-voucher', false)
            ->assertSee('Darujte izbor satova, naočala i nakita.')
            ->assertDontSee('Odaberi dob, interese i budžet.')
            ->assertDontSee('data-libermedia-category-bundle', false);
        $this->assertSame(route('gift-vouchers.create'), $this->xpath($directory->getContent())->query('//*[@data-watchline-category-gift-voucher]')->item(0)->getAttribute('href'));
    }

    public function test_watchline_does_not_fall_back_to_bundles_when_gift_vouchers_are_disabled(): void
    {
        config(['storefront.home' => 'watchline', 'gift_vouchers.enabled' => false]);
        $this->products(4);
        $response = $this->get(route('shop.index'))->assertOk()
            ->assertDontSee('data-catalog-promo-template=', false)
            ->assertDontSee('Kupi u kompletu i uštedi');
        $this->assertCount(4, $response->viewData('products'));
    }

    private function products(int $count): void
    {
        for ($index = 1; $index <= $count; $index++) {
            $product = Product::create(['code' => 'promo-toy-'.$index, 'sku' => 'promo-toy-'.$index, 'base_price' => 10, 'stock_qty' => 10, 'is_active' => true]);
            $product->translations()->create(['locale' => 'hr', 'name' => 'Igračka '.$index, 'slug' => 'promo-toy-'.$index]);
        }
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($document);
    }
}
