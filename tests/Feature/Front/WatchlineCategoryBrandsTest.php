<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Catalog\WatchlineCategoryBrandService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchlineCategoryBrandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront.home' => 'watchline', 'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia', 'storefront_cache.enabled' => false,
        ]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'WATCH LINE AM - Web Shop',
            'catalog_use_manufacturers' => true, 'catalog_hide_out_of_stock_products' => false,
        ]);
    }

    public function test_category_row_includes_all_descendant_brands_once_and_keeps_the_origin_category_on_each_brand_link(): void
    {
        $category = $this->category('satovi');
        $child = $this->category('muski-satovi', $category);
        $grandchild = $this->category('automatski-satovi', $child);
        $hidden = $this->category('skriveni-satovi', $category, false);
        $unrelated = $this->category('nakit');
        $first = $this->manufacturer('alpha');
        $second = $this->manufacturer('beta');
        $third = $this->manufacturer('gamma');
        config(['manufacturer_logos.'.$first->code => asset('assets/brand/watchline-logo.svg')]);
        $this->product($first, [$category, $child]);
        $this->product($second, [$child]);
        $this->product($third, [$grandchild]);
        $this->product($this->manufacturer('unrelated'), [$unrelated]);
        $this->product($this->manufacturer('hidden-category'), [$hidden]);
        $this->product($this->manufacturer('inactive-product'), [$category], false);
        $inactiveBrand = $this->manufacturer('inactive-brand', false);
        $this->product($inactiveBrand, [$category]);

        $response = $this->get(route('categories.show', ['slug' => 'satovi',
            'manufacturer' => 'alpha', 'q' => 'no matching product', 'promo_only' => 1]))
            ->assertOk()->assertDontSee('data-herrera-subcategory-carousel', false)
            ->assertSee('Brandovi u ovoj kategoriji (3)');
        $xpath = $this->xpath($response->getContent());
        $cards = $xpath->query('//*[@data-watchline-category-brand]');
        $this->assertSame([$first->id, $second->id, $third->id], array_map(
            fn ($node): int => (int) $node->getAttribute('data-watchline-category-brand'), iterator_to_array($cards)
        ));
        foreach ([$first, $second, $third] as $brand) {
            $card = $xpath->query('//*[@data-watchline-category-brand="'.$brand->id.'"]')->item(0);
            $this->assertSame(route('manufacturers.show', ['slug' => $brand->code, 'category' => 'satovi']), $card->getAttribute('href'));
            $this->assertStringNotContainsString('promo_only', $card->getAttribute('href'));
        }
        $this->assertSame(asset('assets/brand/watchline-logo.svg'), $xpath->query('//*[@data-watchline-category-brand="'.$first->id.'"]//img')->item(0)->getAttribute('src'));
        $this->assertSame('beta', trim($xpath->query('//*[@data-watchline-category-brand="'.$second->id.'"]')->item(0)->textContent));
        $this->assertSame(1, $xpath->query('//main/*[contains(@class, "herrera-catalog-heading-band")]/following-sibling::*[1][@data-watchline-category-brands]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-watchline-category-brands]/following-sibling::*[contains(@class, "catalog-filter-section")]')->count());
    }

    public function test_leaf_category_still_has_brands_and_empty_category_has_no_row_or_assets(): void
    {
        $category = $this->category('leaf');
        $this->product($this->manufacturer('leaf-brand'), [$category]);
        $this->get(route('categories.show', ['slug' => 'leaf']))->assertOk()
            ->assertSee('data-watchline-category-brands', false)
            ->assertSee('watchline-category-brands.css', false);
        $this->category('empty');
        $this->get(route('categories.show', ['slug' => 'empty']))->assertOk()
            ->assertDontSee('data-watchline-category-brands', false)
            ->assertDontSee('watchline-category-brands.css', false);
        $this->get(route('shop.index'))->assertOk()->assertDontSee('data-watchline-category-brands', false);
        $this->get(route('manufacturers.show', ['slug' => 'leaf-brand']))->assertOk()->assertDontSee('data-watchline-category-brands', false);
    }

    public function test_service_keeps_every_brand_with_localized_routes_and_respects_catalog_stock_visibility(): void
    {
        $category = $this->category('many-brands');
        foreach (range(1, 16) as $index) {
            $brand = $this->manufacturer('brand-'.$index);
            $brand->translations()->create(['locale' => 'en', 'name' => 'English '.$index, 'slug' => 'english-'.$index]);
            $this->product($brand, [$category]);
        }
        $outOfStock = $this->manufacturer('out-of-stock');
        $this->product($outOfStock, [$category], stock: 0);
        $withoutRoute = $this->manufacturer('without-route');
        $withoutRoute->translations()->update(['slug' => '']);
        $this->product($withoutRoute, [$category]);
        $service = app(WatchlineCategoryBrandService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $brands = $service->forCategoryScope([$category->id], 'en', 'hr', true);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(16, $brands);
        $this->assertSame('english-1', $brands->first()['slug']);
        $this->assertCount(3, $queries, 'Manufacturers, translations and media are batched without per-brand queries.');
        $this->assertCount(17, $service->forCategoryScope([$category->id], 'en', 'hr', false));
        $this->assertSame('out-of-stock', $service->forCategoryScope([$category->id], 'en', 'hr', false)->last()['slug']);
        $this->assertTrue($service->forCategoryScope([], 'hr', 'hr')->isEmpty());
    }

    public function test_other_brands_keep_their_existing_subcategory_tiles(): void
    {
        config(['storefront.home' => 'libermedia']);
        $category = $this->category('original-category');
        $this->category('original-child', $category);
        $this->product($this->manufacturer('original-brand'), [$category]);

        $this->get(route('categories.show', ['slug' => 'original-category']))->assertOk()
            ->assertSee('data-herrera-subcategory-carousel', false)
            ->assertDontSee('data-watchline-category-brands', false);
    }

    private function category(string $slug, ?Category $parent = null, bool $active = true): Category
    {
        $category = Category::create(['code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'parent_id' => $parent?->id, 'is_active' => $active]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => $slug, 'slug' => $slug]);

        return $category;
    }

    private function manufacturer(string $slug, bool $active = true): Manufacturer
    {
        $manufacturer = Manufacturer::create(['code' => $slug, 'is_active' => $active]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $slug, 'slug' => $slug]);

        return $manufacturer;
    }

    private function product(Manufacturer $manufacturer, array $categories, bool $active = true, int $stock = 1): Product
    {
        $code = 'product-'.$manufacturer->code;
        $product = Product::create(['code' => $code, 'is_active' => $active, 'manufacturer_id' => $manufacturer->id, 'base_price' => 100, 'stock_qty' => $stock]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        $product->categories()->attach(collect($categories)->pluck('id'));

        return $product;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
