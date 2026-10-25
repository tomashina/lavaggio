<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Content\ContentBlockResolver;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchlinePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront.home' => 'watchline',
            'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
        ]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Watchline', 'store_pricing_prices_include_tax' => true,
            'front_category_products_per_page_desktop' => 2, 'catalog_hide_out_of_stock_products' => false,
        ]);
    }

    public function test_gift_default_aggregates_paid_sales_once_and_loads_full_content_only_for_visible_cards(): void
    {
        $watches = Category::create(['code' => 'herrera-oc-category-66', 'scope' => 'catalog', 'is_active' => true]);
        $watches->translations()->create(['locale' => 'hr', 'name' => 'Satovi', 'slug' => 'satovi']);
        $firstBrand = $this->brand('cluse');
        $secondBrand = $this->brand('guess');
        $zero = $this->product('zero-sales', $firstBrand, $watches);
        $cancelled = $this->product('cancelled-sales', $secondBrand, $watches);
        $winner = $this->product('winner', $firstBrand, $watches);
        $tied = $this->product('tied', $secondBrand, $watches);
        $pending = $this->product('pending-sales', $firstBrand, $watches);
        $paid = OrderStatus::create(['code' => 'paid-perf', 'name' => 'Paid', 'is_paid' => true, 'is_cancelled' => false]);
        $completed = OrderStatus::create(['code' => 'watchline-oc-status-5', 'name' => 'Completed source', 'is_paid' => false, 'is_cancelled' => false]);
        $cancelledStatus = OrderStatus::create(['code' => 'cancelled-perf', 'name' => 'Cancelled', 'is_paid' => true, 'is_cancelled' => true]);
        $pendingStatus = OrderStatus::create(['code' => 'pending-perf', 'name' => 'Pending', 'is_paid' => false, 'is_cancelled' => false]);
        $this->sale($winner, 2, $paid);
        $this->sale($winner, 3, $completed);
        $this->sale($tied, 5, $completed);
        $this->sale($cancelled, 100, $cancelledStatus);
        $this->sale($pending, 100, $pendingStatus);
        $this->sale($zero, 100, null);
        $this->sale($zero, 0, $paid);

        DB::enableQueryLog();
        $response = $this->get(route('gift-guide.index'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $products = $response->viewData('products');
        $this->assertSame([$winner->id, $tied->id], $products->pluck('id')->all());
        $this->assertSame(5, $products->total());
        $this->assertSame($winner->payload, $products->first()->payload);
        $this->assertSame(100.0, (float) $products->first()->base_price);
        $this->assertSame('Watch winner', $products->first()->translations->first()->name);
        $this->assertEqualsCanonicalizing([$firstBrand->id, $secondBrand->id], $response->viewData('giftBrands')->pluck('id')->all());
        $ranking = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'as gift_sales')));
        $this->assertCount(1, $ranking, 'The complete candidate ranking must be one aggregate query.');
        $this->assertStringContainsString('left join (select gift_items.product_id, SUM(', $ranking[0]['query']);
        $this->assertStringNotContainsString('"products".*', $ranking[0]['query'], 'Default ranking does not need every imported product payload.');
        $this->assertStringNotContainsString('"gift_items"."product_id" = "products"."id"', $ranking[0]['query'], 'Do not scan sales once for every product.');

        $this->get(route('gift-guide.index', ['page' => 2]))->assertOk()
            ->assertViewHas('products', fn ($page) => $page->pluck('id')->all() === [$zero->id, $cancelled->id]);
        $this->get(route('gift-guide.index', ['brand' => $secondBrand->id]))->assertOk()
            ->assertViewHas('products', fn ($page) => $page->pluck('id')->all() === [$tied->id, $cancelled->id]);
    }

    public function test_automatic_widget_does_not_hydrate_unused_manual_media_and_switching_to_manual_preserves_selection(): void
    {
        $watches = Category::create(['code' => 'performance-watches', 'scope' => 'catalog', 'is_active' => true]);
        $watches->translations()->create(['locale' => 'hr', 'name' => 'Satovi', 'slug' => 'performance-watches']);
        $unused = $this->product('old-manual-cluse', $this->brand('cluse'), $watches);
        $selected = $this->product('automatic-guess', $this->brand('guess'), $watches);
        $block = ContentBlock::create(['code' => 'performance-auto', 'name' => 'Automatic', 'type' => 'watchline_products', 'is_active' => true]);
        $translation = $block->translations()->create(['locale' => 'hr', 'title' => 'Automatic', 'payload' => ['items_limit' => 1, 'watchline' => ['source' => 'guess_newest']]]);
        $block->items()->create(['item_type' => 'product', 'item_id' => $unused->id, 'sort_order' => 0]);
        $block->slots()->create(['placement' => 'home.content', 'frontend_variant' => 'all', 'sort_order' => 1, 'is_active' => true]);

        DB::enableQueryLog();
        $response = $this->get('/')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $response->assertSee('data-product-id="'.$selected->id.'"', false)
            ->assertDontSee('data-product-id="'.$unused->id.'"', false);
        $unusedMedia = array_filter($queries, fn ($query) => str_contains($query['query'], 'from "media"')
            && in_array($unused->getMorphClass(), $query['bindings'], true)
            && str_contains($query['query'], '"media"."model_id" in ('.$unused->id.')'));
        $this->assertCount(0, $unusedMedia, 'Automatic widgets must not hydrate media for discarded manual products.');

        $translation->update(['payload' => ['items_limit' => 1, 'watchline' => ['source' => 'manual']]]);
        ContentBlockResolver::bumpCacheVersion();
        $this->get('/')->assertOk()->assertSee('data-product-id="'.$unused->id.'"', false)
            ->assertDontSee('data-product-id="'.$selected->id.'"', false);
    }

    private function brand(string $name): Manufacturer
    {
        $brand = Manufacturer::create(['code' => 'perf-'.$name, 'is_active' => true]);
        $brand->translations()->create(['locale' => 'hr', 'name' => strtoupper($name), 'slug' => $name === 'guess' ? 'guess-satovi' : $name]);

        return $brand;
    }

    private function product(string $name, Manufacturer $brand, Category $category): Product
    {
        $product = Product::create(['code' => 'perf-'.$name, 'sku' => 'perf-'.$name, 'manufacturer_id' => $brand->id,
            'is_active' => true, 'base_price' => 100, 'stock_qty' => 2,
            'payload' => ['opencart' => ['image' => 'catalog/performance/'.$name.'.jpg', 'description_marker' => str_repeat('public catalog content ', 100)]]]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Watch '.$name, 'slug' => 'perf-'.$name]);
        $product->categories()->attach($category);

        return $product;
    }

    private function sale(Product $product, int $quantity, ?OrderStatus $status): void
    {
        $id = DB::table('orders')->insertGetId(['order_number' => 'PERF-'.DB::table('orders')->count(), 'status_id' => $status?->id, 'customer_name' => 'Fixture', 'customer_email' => 'fixture@example.test']);
        DB::table('order_items')->insert(['order_id' => $id, 'product_id' => $product->id, 'quantity' => $quantity, 'name' => 'Fixture watch']);
    }
}
