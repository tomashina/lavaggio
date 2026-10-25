<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WatchlinePaginationSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false,
            'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Watchline',
            'catalog_use_blog' => true, 'catalog_use_manufacturers' => true, 'front_category_products_per_page_desktop' => 1]);
        $category = Category::create(['scope' => 'catalog', 'code' => 'herrera-oc-category-66', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Satovi', 'slug' => 'satovi']);
        $pages = Category::create(['scope' => 'page', 'code' => 'seo-pages', 'is_active' => true]);
        $pages->translations()->create(['locale' => 'hr', 'scope' => 'page', 'name' => 'Informacije', 'slug' => 'informacije']);
        $brand = Manufacturer::create(['code' => 'seo-brand', 'is_active' => true]);
        $brand->translations()->create(['locale' => 'hr', 'name' => 'Brend', 'slug' => 'seo-brand']);
        foreach (range(1, 5) as $index) {
            $product = Product::create(['code' => 'pagination-'.$index, 'sku' => 'pagination-'.$index,
                'is_active' => true, 'stock_qty' => 1, 'base_price' => 100, 'manufacturer_id' => $brand->id]);
            $product->translations()->create(['locale' => 'hr', 'name' => 'Sat '.$index, 'slug' => 'pagination-'.$index]);
            $product->categories()->attach($category);
        }
    }

    #[DataProvider('paginatedRoutes')]
    public function test_nonexistent_later_pages_return_a_real_noindex_404_instead_of_an_empty_indexable_page(string $path): void
    {
        $this->get($path.'?page=9999')->assertNotFound()
            ->assertSee('<title>Stranica nije pronađena</title>', false)
            ->assertSee('noindex', false)
            ->assertDontSee('rel="canonical"', false)
            ->assertDontSee('type="application/ld+json"', false);
        $this->get($path)->assertOk();
    }

    public static function paginatedRoutes(): array
    {
        return [['/shop'], ['/category/satovi'], ['/manufacturer/seo-brand'], ['/savjeti-za-poklone'],
            ['/blog'], ['/iskustva-kupaca'], ['/pages/category/informacije']];
    }

    public function test_a_valid_second_page_and_empty_first_filter_result_stay_accessible(): void
    {
        $this->get('/category/satovi?page=2')->assertOk()->assertViewHas('products', fn ($products) => $products->currentPage() === 2 && $products->count() === 1);
        $this->get('/savjeti-za-poklone?recipient=children')->assertOk()->assertViewHas('products', fn ($products) => $products->total() === 0);
    }

    public function test_other_storefronts_keep_their_existing_pagination_behavior(): void
    {
        config(['storefront.home' => 'auto']);
        $this->get('/shop?page=9999')->assertOk();
    }
}
