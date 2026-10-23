<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchlineGiftGuideTest extends TestCase
{
    use RefreshDatabase;

    private array $categories;

    private Manufacturer $cluse;

    private Manufacturer $seiko;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront.home' => 'watchline',
            'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'commerce.b2c_only' => true,
            'commerce.b2b_only' => false, 'storefront_cache.enabled' => false, 'gift_vouchers.enabled' => true,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr']);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Watchline', 'store_pricing_b2c_enabled' => true,
            'store_pricing_prices_include_tax' => true, 'catalog_hide_out_of_stock_products' => false,
            'front_category_products_per_page_desktop' => 24]);
        $watches = $this->category(66, 'satovi', 'Satovi');
        $glasses = $this->category(67, 'naocale', 'Sunčane naočale');
        $jewelry = $this->category(70, 'nakit', 'Nakit');
        $women = $this->category(63, 'zenski-satovi', 'Ženski satovi', $watches);
        $this->categories = [
            'watches' => $watches, 'women' => $women,
            'women_brand' => $this->category(82, 'zenski-cluse', 'CLUSE', $women),
            'men' => $this->category(64, 'muski-satovi', 'Muški satovi', $watches),
            'children' => $this->category(65, 'djecji-satovi', 'Dječji satovi', $watches),
            'smart' => $this->category(76, 'pametni-satovi', 'Pametni satovi', $watches),
            'glasses' => $glasses,
            'women_glasses' => $this->category(69, 'zenske-naocale', 'Sunčane naočale za žene', $glasses),
            'men_glasses' => $this->category(68, 'muske-naocale', 'Sunčane naočale za muškarce', $glasses),
            'jewelry' => $jewelry, 'necklaces' => $this->category(71, 'ogrlice', 'Ogrlice', $jewelry),
            'outside' => $this->category(999, 'igracke', 'Igračke'),
        ];
        $this->cluse = $this->brand('cluse', 'CLUSE');
        $this->seiko = $this->brand('seiko', 'SEIKO');
    }

    public function test_watchline_uses_real_gift_catalog_and_branding_without_toy_filters_or_inline_styles(): void
    {
        $watch = $this->product('women-real', 'women_brand');
        $glasses = $this->product('glasses-real', 'women_glasses');
        $jewel = $this->product('jewel-real', 'necklaces');
        $this->product('unrelated-toy', 'outside');
        $this->product('inactive', 'women', ['is_active' => false]);
        $response = $this->get(route('gift-guide.index'))->assertOk()
            ->assertViewIs('front.desktop.gift-guide.watchline')
            ->assertSee('data-watchline-gift-guide', false)->assertSee('Za nju')->assertSee('Za njega')->assertSee('Za djecu')
            ->assertSee('Do 300 €')->assertSee('Više od 300 €')->assertSee('Sunčane naočale')
            ->assertDontSee('Dob djeteta')->assertDontSee('Sve male avanture')->assertDontSee('Liber Media')
            ->assertSee(route('gift-vouchers.create'), false)->assertSee('watchline-gift-guide.js', false);
        $this->assertEqualsCanonicalizing([$watch->id, $glasses->id, $jewel->id], $response->viewData('products')->pluck('id')->all());
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(3, $xpath->query('//*[@data-watchline-gift-guide]//*[@data-product-card]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-watchline-gift-guide]//*[@style]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-watchline-gift-guide]//h1')->length);
    }

    public function test_recipient_type_and_descendant_taxonomy_filters_intersect_without_guessing_unclassified_gender(): void
    {
        $women = $this->product('female-watch', 'women_brand');
        $women->categories()->attach($this->categories['watches']);
        $men = $this->product('male-watch', 'men');
        $children = $this->product('child-watch', 'children');
        $femaleGlasses = $this->product('female-glasses', 'women_glasses');
        $this->product('male-glasses', 'men_glasses');
        $jewel = $this->product('unclassified-jewel', 'necklaces');
        $this->product('unclassified-smartwatch', 'smart');

        $this->assertSelection(['recipient' => 'for_her', 'type' => 'watches'], [$women]);
        $this->assertSelection(['recipient' => 'for_her', 'type' => 'sunglasses'], [$femaleGlasses]);
        $this->assertSelection(['recipient' => 'for_him', 'type' => 'watches'], [$men]);
        $this->assertSelection(['recipient' => 'children'], [$children]);
        $this->assertSelection(['recipient' => 'all', 'type' => 'jewelry'], [$jewel]);
        $this->get(route('gift-guide.index', ['recipient' => 'for_her', 'type' => 'jewelry']))->assertOk()
            ->assertSee('Za još više ideja odaberite „Za sve” i istražite cijelu ponudu.')
            ->assertViewHas('products', fn ($products) => $products->total() === 0);
    }

    public function test_budget_and_sort_use_the_real_promotional_price_and_brand_choices_follow_the_eligible_scope(): void
    {
        $sale = $this->product('sale-costs-80', 'women', ['base_price' => 200]);
        $this->promotion($sale, 60);
        $cheaper = $this->product('costs-70', 'women', ['base_price' => 70, 'manufacturer_id' => $this->seiko->id]);
        $this->product('costs-150', 'women', ['base_price' => 150]);
        $this->product('wrong-recipient', 'men', ['base_price' => 20]);

        $response = $this->get(route('gift-guide.index', ['recipient' => 'for_her', 'budget' => 100, 'sort' => 'price_low']))->assertOk();
        $this->assertSame([$cheaper->id, $sale->id], $response->viewData('products')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->cluse->id, $this->seiko->id], $response->viewData('giftBrands')->pluck('id')->all());
        $filtered = $this->get(route('gift-guide.index', ['recipient' => 'for_her', 'budget' => 100, 'brand' => $this->cluse->id, 'promo_only' => 1]))->assertOk();
        $this->assertSame([$sale->id], $filtered->viewData('products')->pluck('id')->all());
        $this->assertSelection(['recipient' => 'for_her', 'budget' => 100, 'brand' => $this->seiko->id, 'promo_only' => 1], []);
    }

    public function test_budget_boundaries_and_price_descending_are_disjoint_at_three_hundred_euros(): void
    {
        $fifty = $this->product('exactly-50', 'women', ['base_price' => 50]);
        $threeHundred = $this->product('exactly-300', 'women', ['base_price' => 300]);
        $above = $this->product('over-300', 'men', ['base_price' => 300.01]);
        $this->assertSelection(['budget' => 50], [$fifty]);
        $this->assertSelection(['budget' => 300, 'sort' => 'price_high'], [$threeHundred, $fifty], ordered: true);
        $this->assertSelection(['budget' => '300_plus'], [$above]);
    }

    public function test_stock_switch_uses_own_availability_and_global_stock_visibility_remains_authoritative(): void
    {
        $local = $this->product('local-stock', 'men', ['stock_qty' => 1]);
        $supplier = $this->product('supplier-stock', 'men', ['stock_qty' => 0, 'supplier_stock_qty' => 5]);
        $empty = $this->product('empty-stock', 'men', ['stock_qty' => 0, 'supplier_stock_qty' => 0]);
        $this->assertSelection(['recipient' => 'for_him', 'available_only' => 1], [$local]);
        $this->assertSelection(['recipient' => 'for_him'], [$local, $supplier, $empty]);
        app(SystemSettingsService::class)->put('catalog_hide_out_of_stock_products', true);
        $this->assertSelection(['recipient' => 'for_him'], [$local, $supplier]);
    }

    public function test_default_ranking_counts_completed_quantities_and_excludes_cancelled_or_unplaced_orders(): void
    {
        $first = $this->product('rank-first', 'women');
        $best = $this->product('rank-best', 'women');
        $complete = OrderStatus::create(['code' => 'herrera-oc-status-5', 'name' => 'Complete', 'is_paid' => false, 'is_cancelled' => false]);
        $cancelled = OrderStatus::create(['code' => 'cancelled-gift', 'name' => 'Cancelled', 'is_paid' => true, 'is_cancelled' => true]);
        $this->sale($first, 2, $complete);
        $this->sale($best, 5, $complete);
        $this->sale($first, 100, $cancelled);
        $this->sale($first, 100, null);

        $this->assertSelection([], [$best, $first], ordered: true);
    }

    public function test_sort_columns_and_pagination_preserve_every_selected_filter_and_other_brand_options(): void
    {
        app(SystemSettingsService::class)->put('front_category_products_per_page_desktop', 2);
        $first = $this->product('page-first', 'women', ['base_price' => 20]);
        $second = $this->product('page-second', 'women', ['base_price' => 30]);
        $third = $this->product('page-third', 'women', ['base_price' => 40]);
        foreach ([$first, $second, $third] as $product) {
            $this->promotion($product, 10);
        }
        $otherBrand = $this->product('page-other-brand', 'women', ['base_price' => 50, 'manufacturer_id' => $this->seiko->id]);
        $this->promotion($otherBrand, 10);
        $filters = ['recipient' => 'for_her', 'type' => 'watches', 'budget' => 100, 'brand' => $this->cluse->id,
            'available_only' => 1, 'promo_only' => 1, 'sort' => 'price_low', 'cols' => 3];
        $firstPage = $this->get(route('gift-guide.index', $filters))->assertOk();
        $this->assertSame([$first->id, $second->id], $firstPage->viewData('products')->pluck('id')->all());
        $this->assertSame(3, $firstPage->viewData('products')->total());
        $this->assertEqualsCanonicalizing([$this->cluse->id, $this->seiko->id], $firstPage->viewData('giftBrands')->pluck('id')->all());
        $next = $firstPage->viewData('products')->nextPageUrl();
        parse_str(parse_url($next, PHP_URL_QUERY), $nextFilters);
        foreach ($filters as $key => $value) {
            $this->assertSame((string) $value, $nextFilters[$key]);
        }
        $this->get($next)->assertOk()->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$third->id]);
        $xpath = $this->xpath($firstPage->getContent());
        $this->assertSame('price_low', $xpath->query('//*[@data-watchline-gift-guide]//select[@name="sort"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('watchline-gift-filters', $xpath->query('//*[@data-watchline-gift-guide]//input[@name="promo_only"]')->item(0)->getAttribute('form'));
    }

    public function test_disabled_vouchers_are_not_promoted_and_other_brands_keep_their_original_gift_page(): void
    {
        $this->product('guide-watch', 'women');
        config(['gift_vouchers.enabled' => false]);
        $this->get(route('gift-guide.index'))->assertOk()->assertDontSee('data-watchline-gift-voucher', false);
        config(['storefront.home' => 'libermedia']);
        $this->get(route('gift-guide.index'))->assertOk()->assertViewIs('front.desktop.gift-guide.index')
            ->assertSee('Dob djeteta')->assertDontSee('data-watchline-gift-guide', false);
    }

    private function category(int $id, string $slug, string $name, ?Category $parent = null): Category
    {
        $category = Category::create(['code' => 'herrera-oc-category-'.$id, 'scope' => 'catalog', 'is_active' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => $name, 'slug' => $slug]);

        return $category;
    }

    private function brand(string $code, string $name): Manufacturer
    {
        $brand = Manufacturer::create(['code' => 'gift-'.$code, 'is_active' => true]);
        $brand->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => 'gift-'.$code]);

        return $brand;
    }

    private function product(string $name, string $category, array $attributes = []): Product
    {
        $product = Product::create($attributes + ['code' => 'gift-'.$name, 'sku' => 'gift-'.$name, 'manufacturer_id' => $this->cluse->id,
            'is_active' => true, 'base_price' => 80, 'stock_qty' => 2, 'payload' => ['opencart' => ['image' => 'catalog/Cluse/test.jpg']]]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Watchline '.$name, 'slug' => 'gift-'.$name]);
        $product->categories()->attach($this->categories[$category]);

        return $product;
    }

    private function promotion(Product $product, int $percentage): void
    {
        $action = CatalogAction::create(['code' => 'gift-promo-'.$product->id, 'scope' => 'product', 'type' => 'percentage',
            'discount_value' => $percentage, 'target_type' => 'product', 'audience_type' => 'all', 'is_active' => true]);
        $action->targets()->create(['target_type' => 'product', 'target_id' => $product->id]);
    }

    private function sale(Product $product, int $quantity, ?OrderStatus $status): void
    {
        $id = DB::table('orders')->insertGetId(['order_number' => 'GIFT-'.DB::table('orders')->count(), 'status_id' => $status?->id, 'customer_name' => 'Fixture', 'customer_email' => 'fixture@example.test']);
        DB::table('order_items')->insert(['order_id' => $id, 'product_id' => $product->id, 'quantity' => $quantity, 'name' => 'Fixture watch']);
    }

    private function assertSelection(array $query, array $products, bool $ordered = false): void
    {
        $response = $this->get(route('gift-guide.index', $query))->assertOk();
        $actual = $response->viewData('products')->pluck('id')->all();
        $expected = array_map(fn (Product $product) => $product->id, $products);
        $ordered ? $this->assertSame($expected, $actual) : $this->assertEqualsCanonicalizing($expected, $actual);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($document);
    }
}
