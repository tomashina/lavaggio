<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Services\Analytics\StorefrontEcommerce;
use App\Services\Front\CheckoutService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Js;
use Tests\TestCase;

class LavaggioEcommerceTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_sends_actual_stock_quantities_and_discounted_merchandise_on_the_receipt(): void
    {
        // Production rendering on an in-memory test database. No tracker or provider is contacted.
        Http::preventStrayRequests();
        $this->app['env'] = 'production';
        $token = str_repeat('t', 40);
        $this->withSession(['_token' => $token])->withHeader('X-CSRF-TOKEN', $token);
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany(['store_pricing_prices_include_tax' => true,
            'store_analytics_enabled' => true, 'store_analytics_ga4_measurement_id' => 'G-LOCALTEST']);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'new', 'name' => 'Nova', 'is_default' => true, 'is_active' => true]);
        PaymentMethod::create(['code' => 'cod', 'name' => 'Pouzeće', 'provider' => 'cod', 'fee_type' => 'fixed', 'fee_value' => 1, 'is_active' => true]);
        ShippingMethod::create(['code' => 'standard', 'name' => 'Dostava', 'price' => 4, 'is_active' => true, 'settings' => ['tax_rate_percent' => 25]]);
        $vat = TaxRate::create(['code' => 'VAT25', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_active' => true]);
        $product = Product::create(['code' => 'LOCAL-1', 'sku' => 'LOCAL-1', 'base_price' => 12.50, 'stock_qty' => 3, 'tax_rate_id' => $vat->id, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sapun', 'slug' => 'sapun']);
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2])->assertOk()
            ->assertJsonPath('ecommerce.name', 'add_to_cart')->assertJsonPath('ecommerce.params.value', 20);
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 8])->assertOk()
            ->assertJsonPath('ecommerce.params.items.0.quantity', 1)->assertJsonPath('ecommerce.params.items.0.price', 10);
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id])->assertOk()->assertJsonPath('ecommerce', null);
        $this->patch(route('cart.items.update', $product), ['quantity' => 2])->assertRedirect()
            ->assertSessionHas('cart_ecommerce.name', 'remove_from_cart');
        $coupon = CatalogAction::create(['code' => 'SAVE10', 'scope' => 'cart', 'type' => CatalogAction::TYPE_PERCENTAGE,
            'discount_value' => 10, 'target_type' => CatalogAction::TARGET_PRODUCT, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'coupon_code' => 'SAVE10', 'is_active' => true]);
        $coupon->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id]);
        $other = Product::create(['code' => 'LOCAL-2', 'sku' => 'LOCAL-2', 'base_price' => 6.25, 'stock_qty' => 3, 'tax_rate_id' => $vat->id, 'is_active' => true]);
        $other->translations()->create(['locale' => 'hr', 'name' => 'Miris', 'slug' => 'miris']);
        $this->postJson(route('cart.items.store'), ['product_id' => $other->id])->assertOk();
        $this->assertTrue(app(\App\Services\Front\CartService::class)->applyCoupon('SAVE10'));
        $this->get(route('checkout.create'))->assertOk()->assertSee('data-ga4-checkout-form', false);
        $this->getJson(route('checkout.options'))->assertOk()->assertJsonPath('ecommerce.value', 23)
            ->assertJsonPath('ecommerce.items.0.price', 9)->assertJsonPath('ecommerce.items.1.price', 5);

        $order = app(CheckoutService::class)->placeOrder(['customer_first_name' => 'Test', 'customer_last_name' => 'Kupac',
            'customer_email' => 'fixture@example.test', 'customer_phone' => '+38500123456',
            'billing_address_line_1' => 'Test 1', 'billing_city' => 'Pula', 'billing_postal_code' => '52100', 'billing_country_code' => 'HR',
            'shipping_address_line_1' => 'Test 1', 'shipping_city' => 'Pula', 'shipping_postal_code' => '52100', 'shipping_country_code' => 'HR',
            'shipping_method_code' => 'standard', 'payment_method_code' => 'cod']);
        $purchase = StorefrontEcommerce::purchase($order);
        $this->assertSame(23.0, $purchase['value']);
        $this->assertSame(4.0, $purchase['shipping']);
        $this->assertSame(6.75, $purchase['tax']);
        $this->assertSame($order->order_number, $purchase['transaction_id']);
        $this->assertSame('SAVE10', $purchase['coupon']);
        $this->assertSame([9.0, 5.0], array_column($purchase['items'], 'price'));
        $this->assertStringNotContainsString('fixture@example.test', json_encode($purchase));
        $receipt = route('checkout.success', ['orderNumber' => $order->order_number]);
        $this->withSession(['front.checkout.last_order_id' => $order->id])->get($receipt)
            ->assertOk()->assertSee(Js::from($purchase)->toHtml(), false);
        $order->update(['payment_method_code' => 'corvus']);
        $this->get($receipt)->assertOk()->assertDontSee('ShopAnalytics.trackOnce(', false);
        $order->update(['payload' => [...$order->payload, 'corvuspay' => ['preauthorized_at' => now()->toIso8601String()]]]);
        $this->get($receipt)->assertOk()->assertSee(Js::from($purchase)->toHtml(), false);
        $order->update(['status_id' => OrderStatus::create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => true])->id]);
        $this->get($receipt)->assertOk()->assertDontSee('ShopAnalytics.trackOnce(', false);
        Http::assertNothingSent();
    }
}
