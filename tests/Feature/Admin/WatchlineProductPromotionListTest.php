<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Manager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class WatchlineProductPromotionListTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private int $actionIndex = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        config(['storefront.home' => 'watchline', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->putMany([
            'catalog_use_actions' => true, 'store_pricing_prices_include_tax' => true,
        ]);
        $this->staff = User::factory()->create(['account_type' => 'staff']);
    }

    public function test_list_shows_real_product_category_and_manufacturer_promotions_with_regular_prices(): void
    {
        $category = Category::create(['code' => 'promotion-list-category', 'scope' => 'catalog', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'name' => 'Satovi', 'slug' => 'promotion-list-category']);
        $brand = Manufacturer::create(['code' => 'promotion-list-brand', 'is_active' => true]);
        $direct = $this->product('direct', 120);
        $categoryProduct = $this->product('category', 100);
        $categoryProduct->categories()->attach($category);
        $brandProduct = $this->product('brand', 200, $brand);
        $overlap = $this->product('overlap', 100, $brand);
        $overlap->categories()->attach($category);
        $this->action('product', $direct->id, 20, ['type' => 'fixed_amount']);
        $this->action('category', $category->id, 20);
        $this->action('manufacturer', $brand->id, 15);
        // Exclusivity/priority remain resolver ordering rules; do not invent a second calculation.
        $this->action('product', $overlap->id, 10, ['is_exclusive' => true, 'priority' => 999]);

        $component = $this->manager()->assertSee('Akcijska cijena')->assertSee('Redovna cijena');
        $xpath = $this->xpath($component->html());
        foreach ([[$direct, 100, 120], [$categoryProduct, 80, 100], [$brandProduct, 170, 200], [$overlap, 80, 100]] as [$product, $current, $regular]) {
            $this->assertPrices($xpath, $product, $current, $regular);
            $public = app(ProductPricePresentationService::class)->forProduct($product->fresh(['categories', 'taxRate']), user: null);
            $this->assertSame((float) $current, $public['current_gross']);
        }
    }

    public function test_expired_future_inactive_coupon_private_and_cart_rules_do_not_create_a_public_sale(): void
    {
        $product = $this->product('excluded', 100);
        foreach ([
            ['ends_at' => now()->subMinute()], ['starts_at' => now()->addMinute()], ['is_active' => false],
            ['coupon_code' => 'PRIVATE90'], ['audience_type' => 'user', 'user_id' => $this->staff->id],
            ['audience_type' => 'user_group'], ['scope' => 'cart'],
        ] as $overrides) {
            $this->action('product', $product->id, 90, $overrides);
        }

        $component = $this->manager()->assertDontSee('data-admin-public-promotion', false);
        $this->assertSame(0, $this->xpath($component->html())->query('//del[@data-admin-public-regular-price]')->count());
        $component->assertViewHas('publicPrices', fn ($prices) => $prices[$product->id]['current_gross'] === 100.0
            && ! $prices[$product->id]['has_promotional_discount']);
    }

    public function test_authenticated_staff_specific_discount_never_replaces_the_guest_public_price(): void
    {
        $product = $this->product('guest-context', 100);
        $this->action('product', $product->id, 20);
        $this->action('product', $product->id, 90, ['audience_type' => 'user', 'user_id' => $this->staff->id]);
        $this->actingAs($this->staff);
        $prices = app(ProductPricePresentationService::class);
        $this->assertSame(10.0, $prices->forProduct($product, $this->staff)['current_gross']);
        $this->assertSame(80.0, $prices->forProduct($product, user: null)['current_gross']);

        $component = $this->manager();
        $this->assertPrices($this->xpath($component->html()), $product, 80, 100);
    }

    public function test_hidden_actions_module_still_shows_live_public_prices_and_other_stores_keep_their_display(): void
    {
        $product = $this->product('feature-guard', 100);
        $this->action('product', $product->id, 20);
        app(SystemSettingsService::class)->put('catalog_use_actions', false);
        $this->assertPrices($this->xpath($this->manager()->html()), $product, 80, 100);

        app(SystemSettingsService::class)->put('catalog_use_actions', true);
        config(['storefront.home' => 'auto']);
        $this->manager()->assertDontSee('data-admin-public-promotion', false)->assertViewHas('publicPrices', []);
    }

    public function test_public_gross_sale_prices_include_the_actual_tax_configuration(): void
    {
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $tax = TaxRate::create(['code' => 'list-vat', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_default' => true, 'is_active' => true]);
        $product = $this->product('net-base', 100);
        $product->update(['tax_rate_id' => $tax->id]);
        $this->action('product', $product->id, 20);

        $this->assertPrices($this->xpath($this->manager()->html()), $product, 100, 125);
    }

    public function test_a_full_page_shares_action_target_and_default_tax_lookups_without_per_product_queries(): void
    {
        TaxRate::create(['code' => 'default-list-vat', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_default' => true, 'is_active' => true]);
        $category = Category::create(['code' => 'batch-category', 'scope' => 'catalog', 'is_active' => true]);
        foreach (range(1, 15) as $index) {
            $this->product('batch-'.$index, 100)->categories()->attach($category);
        }
        $this->action('category', $category->id, 20);

        DB::enableQueryLog();
        $component = $this->manager();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $selects = fn (string $table) => array_filter($queries, fn ($query) => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], 'from "'.$table.'"'));

        $this->assertSame(15, $this->xpath($component->html())->query('//*[@data-admin-public-promotion]')->count());
        $this->assertCount(1, $selects('catalog_actions'));
        $this->assertCount(1, $selects('catalog_action_targets'));
        $this->assertCount(1, $selects('tax_rates'));
        $categoryRelations = array_filter($selects('categories'), fn ($query) => str_contains($query['query'], 'join "category_product"'));
        $this->assertCount(1, $categoryRelations, 'Product categories must be eager loaded once for all rows.');
    }

    private function manager()
    {
        return Livewire::withQueryParams(['locale' => 'hr'])->actingAs($this->staff)->test(Manager::class);
    }

    private function product(string $suffix, float $price, ?Manufacturer $brand = null): Product
    {
        $product = Product::create(['code' => 'sale-list-'.$suffix, 'sku' => 'sale-list-'.$suffix, 'base_price' => $price, 'stock_qty' => 5, 'manufacturer_id' => $brand?->id, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sat '.$suffix, 'slug' => 'sale-list-'.$suffix]);

        return $product;
    }

    private function action(string $target, ?int $id, float $discount, array $overrides = []): CatalogAction
    {
        $action = CatalogAction::create(array_replace([
            'code' => 'sale-list-action-'.(++$this->actionIndex), 'scope' => 'product', 'type' => 'percentage',
            'discount_value' => $discount, 'target_type' => $target, 'audience_type' => 'all',
            'is_active' => true, 'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
        ], $overrides));
        if ($target !== 'all') {
            $action->targets()->create(['target_type' => $target, 'target_id' => $id]);
        }

        return $action;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new DOMXPath($dom);
    }

    private function assertPrices(DOMXPath $xpath, Product $product, float $current, float $regular): void
    {
        $scope = '//*[@data-admin-public-promotion="'.$product->id.'"]';
        $this->assertSame(number_format($current, 2).' €', trim($xpath->query($scope.'//*[@data-admin-public-current-price]')->item(0)?->textContent ?? ''));
        $this->assertSame(number_format($regular, 2).' €', trim($xpath->query($scope.'//del[@data-admin-public-regular-price]')->item(0)?->textContent ?? ''));
        $this->assertSame('Redovna cijena', $xpath->query($scope.'//del')->item(0)?->getAttribute('aria-label'));
    }
}
