<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Services\Front\CartRecommendationService;
use App\Services\Front\CartService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LibermediaCartRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['app.locale' => 'hr', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Liber Media', 'store_pricing_b2c_enabled' => true]);
    }

    public function test_recommendations_count_distinct_real_purchases_and_only_include_available_public_products(): void
    {
        $anchor = $this->product('anchor');
        $popular = $this->product('popular');
        $bulk = $this->product('bulk');
        $inactive = $this->product('inactive', ['is_active' => false]);
        $soldOut = $this->product('sold-out', ['stock_qty' => 0]);
        $belowMinimum = $this->product('minimum', ['stock_qty' => 1, 'minimum_order_quantity' => 3]);
        $variantSoldOut = $this->product('variant-sold-out');
        $option = Option::query()->create(['code' => 'size', 'type' => 'radio', 'is_active' => true]);
        $optionValue = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'small', 'is_active' => true]);
        $variantSoldOut->optionValues()->create([
            'option_value_id' => $optionValue->id, 'mode' => 'variant', 'is_active' => true,
            'stock_qty' => 0, 'combination_hash' => hash('sha256', 'variant-sold-out'),
        ]);
        $untranslated = $this->product('untranslated', [], 'de');
        $cancelledOnly = $this->product('cancelled-only');
        $unpaidOnly = $this->product('unpaid-only');
        $this->order([$anchor, $popular]);
        $this->order([$anchor, $popular]);
        $order = $this->order([$anchor, $bulk, $inactive, $soldOut, $belowMinimum, $variantSoldOut, $untranslated]);
        $order->items()->create(['product_id' => $bulk->id, 'name' => 'Second size', 'quantity' => 500, 'unit_price' => 10, 'line_total' => 5000]);
        $cancelledStatus = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_paid' => true, 'is_cancelled' => true]);
        $this->order([$anchor, $cancelledOnly], ['status_id' => $cancelledStatus->id]);
        $this->order([$anchor, $unpaidOnly], ['paid_at' => null]);

        $cart = app(CartService::class);
        $cart->replaceRaw([['product_id' => $anchor->id, 'quantity' => 1]]);
        $result = app(CartRecommendationService::class)->forLines($cart->lines());

        $this->assertSame('co_purchase', $result['source']);
        $this->assertSame([$popular->id, $bulk->id], $result['products']->pluck('id')->all());
        $response = $this->get(route('cart.index'))->assertOk()
            ->assertSee('Kupci su uz ove artikle kupili i')->assertSee('data-cart-native-submits', false)
            ->assertSee('data-recommendation-source="co_purchase"', false)
            ->assertDontSee('private-order@example.test');
        $this->assertSame(2, substr_count($response->getContent(), 'data-product-card data-product-id='));
    }

    public function test_imported_paid_status_is_enough_and_recommendation_count_is_bounded(): void
    {
        $anchor = $this->product('anchor');
        $others = collect(range(1, 10))->map(fn ($n) => $this->product('item-'.$n));
        $paid = OrderStatus::query()->create(['code' => 'completed', 'name' => 'Provedena', 'is_paid' => true]);
        $this->order([$anchor, ...$others->all()], ['paid_at' => null, 'status_id' => $paid->id, 'source' => 'opencart']);
        app(CartService::class)->replaceRaw([['product_id' => $anchor->id, 'quantity' => 1]]);

        $result = app(CartRecommendationService::class)->forLines(app(CartService::class)->lines());
        $this->assertSame('co_purchase', $result['source']);
        $this->assertSame($others->reverse()->take(8)->pluck('id')->values()->all(), $result['products']->pluck('id')->all());
        foreach ($result['products'] as $product) {
            foreach (['translations', 'media', 'categories', 'optionValues', 'attributes'] as $relation) {
                $this->assertTrue($product->relationLoaded($relation));
            }
        }
        $html = $this->get(route('cart.index'))->assertOk()
            ->assertSee('data-cart-recommendations-carousel', false)
            ->assertSee('data-herrera-home-carousel', false)
            ->assertSee('data-cart-native-submits', false)
            ->assertSee('aria-label="Prethodni proizvodi"', false)
            ->assertSee('aria-label="Sljedeći proizvodi"', false)
            ->getContent();
        $this->assertSame(8, substr_count($html, 'data-product-card data-product-id='));
        $this->assertSame(1, preg_match("/data-cart-recommendations-carousel[^>]*data-splide='([^']+)'/", $html, $match));
        $options = json_decode(html_entity_decode($match[1]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(6, $options['perPage']);
        $this->assertSame(4, $options['breakpoints'][1279]['perPage']);
        $this->assertSame(2, $options['breakpoints'][767]['perPage']);
        $this->assertTrue($options['arrows']);
        $this->assertSame(0, $options['reducedMotion']['speed']);
    }

    public function test_without_purchase_history_fallback_uses_related_products_and_visible_catalog_categories_truthfully(): void
    {
        $anchor = $this->product('anchor');
        $related = $this->product('explicit-related');
        $sameCategory = $this->product('same-category');
        $hiddenCategoryProduct = $this->product('hidden-category');
        $unrelated = $this->product('unrelated');
        $category = Category::query()->create(['code' => 'toys', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $hidden = Category::query()->create(['code' => 'hidden', 'scope' => Category::SCOPE_CATALOG, 'is_active' => false]);
        $anchor->categories()->attach([$category->id, $hidden->id]);
        $sameCategory->categories()->attach($category);
        $hiddenCategoryProduct->categories()->attach($hidden);
        $anchor->update(['payload' => ['related_product_ids' => [$related->id, $anchor->id, 99999]]]);
        app(CartService::class)->replaceRaw([['product_id' => $anchor->id, 'quantity' => 1]]);

        $this->get(route('cart.index'))->assertOk()
            ->assertSee('Možda će vam se svidjeti')->assertDontSee('Kupci su uz ove artikle kupili i')
            ->assertViewHas('cartRecommendations', fn ($data) => $data['products']->pluck('id')->all() === [$related->id, $sameCategory->id]);
        $this->assertNotContains($unrelated->id, app(CartRecommendationService::class)->forLines(app(CartService::class)->lines())['products']->pluck('id'));
    }

    public function test_empty_or_unrelated_cart_does_not_show_an_empty_recommendation_block(): void
    {
        $this->get(route('cart.index'))->assertOk()->assertDontSee('data-cart-recommendations', false);
        $anchor = $this->product('anchor');
        $this->product('unrelated');
        app(CartService::class)->replaceRaw([['product_id' => $anchor->id, 'quantity' => 1]]);
        $this->get(route('cart.index'))->assertOk()->assertDontSee('data-cart-recommendations', false);
    }

    public function test_native_add_returns_to_cart_and_refreshes_actual_rows_and_summary(): void
    {
        $anchor = $this->product('anchor');
        $extra = $this->product('extra');
        app(CartService::class)->replaceRaw([['product_id' => $anchor->id, 'quantity' => 1]]);

        $this->from(route('cart.index'))->post(route('cart.items.store'), ['product_id' => $extra->id, 'quantity' => 2])
            ->assertRedirect(route('cart.index'))->assertSessionHas('status');
        $this->get(route('cart.index'))->assertOk()
            ->assertViewHas('lines', fn ($lines) => $lines->pluck('product.id')->all() === [$anchor->id, $extra->id])
            ->assertViewHas('summary', fn ($summary) => $summary['item_qty'] === 3 && (float) $summary['grand_total'] === 30.0);
    }

    public function test_b2c_cart_shows_availability_without_internal_stock_even_for_administrator(): void
    {
        $admin = User::factory()->create(['admin_login_enabled' => true]);
        Bouncer::allow('admin')->to('admin.access');
        Bouncer::assign('admin')->to($admin);
        $this->actingAs($admin);
        app(CartService::class)->replaceRaw([['product_id' => $this->product('anchor', ['supplier_stock_qty' => 19])->id, 'quantity' => 1]]);

        $this->get(route('cart.index'))->assertOk()->assertSee('Dostupno')
            ->assertDontSee('data-cart-staff-stock', false)->assertDontSee('Vlastita zaliha')->assertDontSee('Zaliha dobavljača')
            ->assertViewHas('staffStockByLine', []);
    }

    public function test_coupon_json_updates_summary_and_invalid_coupon_keeps_current_valid_coupon(): void
    {
        $product = $this->product('coupon');
        app(CartService::class)->replaceRaw([['product_id' => $product->id, 'quantity' => 2]]);
        CatalogAction::query()->create([
            'code' => 'cart-coupon', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_ALL, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'coupon_code' => 'SAVE10', 'is_active' => true,
        ]);
        $this->postJson(route('cart.coupon.apply'), ['coupon_code' => 'save10'])->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('coupon_code', 'SAVE10')
            ->assertJsonPath('summary.grand_total', 18)->assertJsonPath('summary.item_qty', 2);
        $this->postJson(route('cart.coupon.apply'), ['coupon_code' => 'NOPE'])->assertUnprocessable()
            ->assertJsonPath('ok', false)->assertJsonPath('coupon_code', 'SAVE10')
            ->assertJsonPath('summary.grand_total', 18)->assertJsonValidationErrors('coupon_code');
        $this->deleteJson(route('cart.coupon.remove'))->assertOk()->assertJsonPath('ok', true)
            ->assertJsonPath('coupon_code', null)->assertJsonPath('summary.grand_total', 20);
        $this->post(route('cart.coupon.apply'), ['coupon_code' => 'SAVE10'])->assertRedirect(route('cart.index'));
    }

    private function product(string $code, array $attributes = [], string $locale = 'hr'): Product
    {
        $product = Product::query()->create(array_merge(['code' => $code, 'sku' => $code, 'is_active' => true, 'base_price' => 10, 'stock_qty' => 20], $attributes));
        $product->translations()->create(['locale' => $locale, 'name' => ucfirst($code), 'slug' => $code]);

        return $product;
    }

    private function order(array $products, array $attributes = []): Order
    {
        $order = Order::query()->create(array_merge([
            'order_number' => 'CO-'.(Order::query()->count() + 1), 'source' => 'web',
            'currency_code' => 'EUR', 'customer_name' => 'Private customer', 'customer_email' => 'private-order@example.test',
            'grand_total' => 20, 'paid_at' => now(),
        ], $attributes));
        foreach ($products as $product) {
            $order->items()->create(['product_id' => $product->id, 'name' => $product->code, 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);
        }

        return $order;
    }
}
