<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WatchlineScopedManufacturerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'hr', 'storefront.home' => 'watchline', 'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia', 'storefront_cache.enabled' => false,
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'WATCH LINE AM - Web Shop',
            'catalog_use_manufacturers' => true, 'catalog_hide_out_of_stock_products' => true,
            'front_category_products_per_page_desktop' => 4, 'front_category_products_per_page_mobile' => 4,
            'store_product_catalog_pagination_mode' => 'load_more']);
    }

    public function test_brand_page_intersects_the_origin_subtree_and_brand_for_products_and_actual_attribute_facets(): void
    {
        $root = $this->category('satovi');
        $men = $this->category('muski-satovi', $root);
        $leaf = $this->category('automatic-muski', $men);
        $women = $this->category('zenski-satovi', $root);
        $brand = $this->manufacturer('casio');
        $black = $this->attribute(38, 'Crna');
        $silver = $this->attribute(38, 'Srebrna');
        $pink = $this->attribute(38, 'Samo ženski');
        $watch = $this->product('mens-casio', $brand, [$men], [$black]);
        $descendant = $this->product('descendant-casio', $brand, [$leaf], [$silver]);
        $this->product('womens-casio', $brand, [$women], [$pink]);
        $this->product('mens-other-brand', $this->manufacturer('other'), [$men], [$this->attribute(38, 'Drugi brand')]);
        $this->product('inactive-mens-casio', $brand, [$men], [$pink], ['is_active' => false]);
        $this->product('sold-out-mens-casio', $brand, [$men], [$pink], ['stock_qty' => 0]);

        $response = $this->get($this->url('casio', 'muski-satovi', ['sort' => 'oldest']))->assertOk();

        $this->assertProducts($response, [$watch->id, $descendant->id]);
        $this->assertTrue($response->viewData('isManufacturerPage'));
        $this->assertSame($brand->id, $response->viewData('manufacturer')->id);
        $this->assertSame($men->id, $response->viewData('manufacturerCategoryContext')->id);
        $this->assertSame([], $response->viewData('optionFilters'));
        $colors = collect($response->viewData('attributeFilters'))->firstWhere('query_key', 'attr_watchline_case_color');
        $this->assertSame(['Crna', 'Srebrna'], array_column($colors['values'], 'label'));
        $this->assertSame([1, 1], array_column($colors['values'], 'count'));
        $response->assertSee('data-watchline-brand-category-context', false)->assertSee('muski-satovi');
        $this->assertSame('CASIO', trim($this->xpath($response)->query('//h1')->item(0)->textContent));
        $selected = $this->get($this->url('casio', 'muski-satovi', ['attr_watchline_case_color' => $black->id]))->assertOk();
        $this->assertProducts($selected, [$watch->id]);
    }

    public function test_desktop_mobile_forms_resets_sort_links_and_load_more_keep_the_category_context(): void
    {
        $men = $this->category('mens-pagination');
        $women = $this->category('womens-pagination');
        $brand = $this->manufacturer('pagination-brand');
        $movement = $this->attribute(19, 'Quartz');
        $expected = [];
        foreach (range(1, 6) as $index) {
            $expected[] = $this->product('pagination-'.$index, $brand, [$men], [$movement], ['base_price' => 100 + $index])->id;
        }
        $this->product('outside-pagination', $brand, [$women], [$movement], ['base_price' => 1]);
        $url = $this->url('pagination-brand', 'mens-pagination', ['sort' => 'price_low', 'attr_watchline_movement' => $movement->id]);
        $response = $this->get($url)->assertOk();
        $this->assertSame(6, $response->viewData('products')->total());
        $this->assertSame(array_slice($expected, 0, 4), $response->viewData('products')->pluck('id')->all());
        $xpath = $this->xpath($response);
        foreach (['category-desktop-filter-form', 'category-mobile-filter-panel'] as $id) {
            $form = $xpath->query('//form[@id="'.$id.'"]')->item(0);
            $this->assertSame($this->url('pagination-brand', 'mens-pagination'), $form->getAttribute('action'));
            $hidden = $xpath->query('.//input[@type="hidden"][@name="category"]', $form);
            $this->assertSame(1, $hidden->count());
            $this->assertSame('mens-pagination', $hidden->item(0)->getAttribute('value'));
        }
        $resetLinks = $xpath->query('//a[contains(@class,"catalog-reset-button") or contains(@class,"catalog-mobile-filter-clear") or parent::*[contains(@class,"catalog-sidebar-active")]]');
        $this->assertSame(3, $resetLinks->count());
        foreach ($resetLinks as $reset) {
            $this->assertSame($this->url('pagination-brand', 'mens-pagination'), $reset->getAttribute('href'));
        }
        $nextUrl = $response->viewData('products')->nextPageUrl();
        parse_str(parse_url($nextUrl, PHP_URL_QUERY), $query);
        $this->assertSame('mens-pagination', $query['category']);
        $this->assertSame('price_low', $query['sort']);
        $this->assertSame((string) $movement->id, $query['attr_watchline_movement']);
        $this->assertSame($nextUrl, $xpath->query('//*[@data-catalog-next-url]')->item(0)->getAttribute('href'));
        $next = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'User-Agent' => 'Mozilla/5.0 (iPhone)'])->get($nextUrl)->assertOk();
        $this->assertSame(array_slice($expected, 4), $next->viewData('products')->pluck('id')->all());
        $this->assertSame('mens-pagination', $next->viewData('filters')['category']);
    }

    public function test_category_navigation_stays_on_the_brand_inside_the_origin_and_respects_two_visible_levels(): void
    {
        $root = $this->category('origin-watches');
        $men = $this->category('origin-men', $root);
        $women = $this->category('origin-women', $root);
        $leaf = $this->category('origin-third-level', $men);
        $outside = $this->category('outside-root');
        $brand = $this->manufacturer('nav-brand');
        $mensWatch = $this->product('nav-mens', $brand, [$leaf]);
        $this->product('nav-womens', $brand, [$women]);
        $this->product('nav-outside', $brand, [$outside]);
        $response = $this->get($this->url('nav-brand', 'origin-watches'))->assertOk();
        $this->assertSame([$men->id, $women->id], $response->viewData('categories')->pluck('id')->all());
        $xpath = $this->xpath($response);
        $links = $xpath->query('//nav[contains(@class,"catalog-sidebar-category-options")]/a[not(@aria-current)]');
        $this->assertSame(2, $links->count());
        $this->assertSame($this->url('nav-brand', 'origin-men'), $links->item(0)->getAttribute('href'));
        $this->assertSame($this->url('nav-brand', 'origin-women'), $links->item(1)->getAttribute('href'));
        $menResponse = $this->get($links->item(0)->getAttribute('href'))->assertOk();
        $this->assertProducts($menResponse, [$mensWatch->id]);
        $this->assertTrue($menResponse->viewData('categories')->isEmpty(), 'Third-level categories stay out of Watchline navigation.');
        $this->assertSame('NAV-BRAND', trim($this->xpath($menResponse)->query('//h1')->item(0)->textContent));
    }

    public function test_unknown_or_newly_hidden_context_returns_no_products_or_facets_instead_of_broadening_the_brand(): void
    {
        $category = $this->category('visible-then-hidden');
        $brand = $this->manufacturer('guard-brand');
        $this->product('guard-watch', $brand, [$category], [$this->attribute(38, 'Crna')]);
        $url = $this->url('guard-brand', 'visible-then-hidden');
        $this->assertSame(1, $this->get($url)->assertOk()->viewData('products')->total());
        $category->update(['is_active' => false]);
        foreach ([$url, $this->url('guard-brand', 'does-not-exist')] as $invalidUrl) {
            $response = $this->get($invalidUrl)->assertOk();
            $this->assertSame(0, $response->viewData('products')->total());
            $this->assertSame([], $response->viewData('attributeFilters'));
            $this->assertTrue($response->viewData('categories')->isEmpty());
            $response->assertSee('Odabrana kategorija nije dostupna');
        }
    }

    public function test_plain_brand_pages_and_other_storefront_context_behavior_remain_unchanged(): void
    {
        $men = $this->category('plain-men');
        $women = $this->category('plain-women');
        $brand = $this->manufacturer('plain-brand');
        $mensWatch = $this->product('plain-mens', $brand, [$men]);
        $womensWatch = $this->product('plain-womens', $brand, [$women]);
        $plain = $this->get(route('manufacturers.show', ['slug' => 'plain-brand']))->assertOk();
        $this->assertProducts($plain, [$mensWatch->id, $womensWatch->id]);
        $plain->assertDontSee('data-watchline-brand-category-context', false);
        $this->assertFalse($plain->viewData('isWatchlineManufacturerCategoryContext'));
        config(['storefront.home' => 'libermedia']);
        $original = $this->get($this->url('plain-brand', 'plain-men'))->assertOk();
        $this->assertProducts($original, [$mensWatch->id]);
        $original->assertDontSee('data-watchline-brand-category-context', false);
        $this->assertFalse($original->viewData('isWatchlineManufacturerCategoryContext'));
    }

    private function url(string $brand, string $category, array $query = []): string
    {
        return route('manufacturers.show', ['slug' => $brand, 'category' => $category, ...$query]);
    }

    private function assertProducts(TestResponse $response, array $ids): void
    {
        $this->assertSame(count($ids), $response->viewData('products')->total());
        $this->assertEqualsCanonicalizing($ids, $response->viewData('products')->pluck('id')->all());
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::create(['code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'hr', 'name' => $slug, 'slug' => $slug]);

        return $category;
    }

    private function manufacturer(string $slug): Manufacturer
    {
        $brand = Manufacturer::create(['code' => $slug, 'is_active' => true]);
        $brand->translations()->create(['locale' => 'hr', 'name' => strtoupper($slug), 'slug' => $slug]);

        return $brand;
    }

    private function attribute(int $source, string $name): Attribute
    {
        $group = 'herrera-oc-attribute-'.$source;
        $code = $group.'-'.substr(hash('sha256', $name), 0, 20);
        $attribute = Attribute::create(['code' => $code, 'group_code' => $group, 'type' => Attribute::TYPE_SELECT, 'is_active' => true]);
        $attribute->translations()->create(['locale' => 'hr', 'group_name' => 'Original', 'name' => $name, 'slug' => $code]);

        return $attribute;
    }

    private function product(string $code, Manufacturer $brand, array $categories, array $attributes = [], array $overrides = []): Product
    {
        $product = Product::create(array_replace(['code' => $code, 'manufacturer_id' => $brand->id, 'base_price' => 100, 'stock_qty' => 2, 'is_active' => true], $overrides));
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        $product->categories()->attach(collect($categories)->pluck('id'));
        $product->attributes()->attach(collect($attributes)->pluck('id'));

        return $product;
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
