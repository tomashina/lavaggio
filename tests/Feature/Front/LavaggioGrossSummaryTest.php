<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Services\Front\CartService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LavaggioGrossSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        CartService::forgetRequestSnapshot();
        Http::preventStrayRequests();
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        PaymentMethod::create(['code' => 'cod', 'name' => 'Pouzeće', 'provider' => 'cod', 'is_active' => true]);
        ShippingMethod::create(['code' => 'standard', 'name' => 'Dostava', 'price' => 4.72, 'is_active' => true, 'settings' => ['tax_rate_percent' => 25]]);
    }

    public function test_cart_checkout_and_refresh_show_gross_prices_and_included_tax(): void
    {
        $this->addProduct('LENOR', 14.99, 25);
        $this->get(route('cart.index'))->assertOk()->assertSee('Međuzbroj (s PDV-om)')
            ->assertSee('Uključeni PDV')->assertSee('data-cart-subtotal>14.99 €', false)
            ->assertDontSee('11.99 €');
        $this->get(route('checkout.create'))->assertOk()
            ->assertSee('data-summary-subtotal>14.99 €', false)
            ->assertSee('data-summary-shipping>5.90 €', false)
            ->assertSee('data-summary-tax>4.18 €', false)
            ->assertSee('data-summary-total>20.89 €', false)
            ->assertSee('Uključeni PDV')->assertSee('Dostava (s PDV-om)');
        $this->getJson(route('checkout.options'))->assertOk()
            ->assertJsonPath('totals.display_subtotal_formatted', '14.99 €')
            ->assertJsonPath('totals.display_shipping_total_formatted', '5.90 €')
            ->assertJsonPath('totals.tax_total_formatted', '4.18 €')
            ->assertJsonPath('totals.grand_total_formatted', '20.89 €')
            // Display must never replace the net accounting amounts.
            ->assertJsonPath('totals.subtotal', 11.99)
            ->assertJsonPath('totals.shipping_total', 4.72);
        $this->assertSame(11.99, app(CartService::class)->summary()['subtotal']);
        Http::assertNothingSent();
    }

    public function test_shipping_changes_use_admin_tax_and_free_shipping_settings(): void
    {
        $this->addProduct('LENOR', 14.99, 25);
        $method = ShippingMethod::where('code', 'standard')->sole();
        $method->update(['settings' => ['tax_rate_percent' => 13]]);
        $this->getJson(route('checkout.options'))->assertOk()
            ->assertJsonPath('totals.display_shipping_total_formatted', '5.33 €')
            ->assertJsonPath('totals.tax_total_formatted', '3.61 €')
            ->assertJsonPath('totals.grand_total_formatted', '20.32 €');
        $method->update(['free_over' => 10]);
        $this->getJson(route('checkout.options'))->assertOk()
            ->assertJsonPath('totals.display_shipping_total_formatted', '0.00 €')
            ->assertJsonPath('totals.tax_total_formatted', '3.00 €')
            ->assertJsonPath('totals.grand_total_formatted', '14.99 €');
    }

    public function test_coupon_discount_includes_tax_for_each_product_rate(): void
    {
        $this->addProduct('VAT25', 25, 25);
        $this->addProduct('VAT13', 11.30, 13);
        CatalogAction::create([
            'code' => 'SUMMARY10', 'scope' => CatalogAction::SCOPE_CART,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_ALL, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'coupon_code' => 'SUMMARY10', 'is_active' => true,
        ]);
        $this->assertTrue(app(CartService::class)->applyCoupon('SUMMARY10'));
        $this->get(route('cart.index'))->assertOk()
            ->assertSee('data-cart-subtotal>36.30 €', false)
            ->assertSee('data-cart-discount>-3.63 €', false);
        $this->get(route('checkout.create'))->assertOk()
            ->assertSee('data-summary-subtotal>36.30 €', false)
            ->assertSee('data-summary-discount>-3.63 €', false)
            ->assertSee('data-summary-tax>6.85 €', false)
            ->assertSee('data-summary-total>38.57 €', false);
        $this->getJson(route('checkout.options'))->assertOk()
            ->assertJsonPath('totals.display_subtotal_formatted', '36.30 €')
            ->assertJsonPath('totals.display_discount_total_formatted', '3.63 €')
            ->assertJsonPath('totals.grand_total_formatted', '38.57 €');
    }

    public function test_product_discount_and_multiple_quantities_show_gross_savings(): void
    {
        $this->addProduct('DETERGENT', 12.50, 25, 3);
        CatalogAction::create([
            'code' => 'PRODUCT10', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_ALL, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true,
        ]);
        CartService::forgetRequestSnapshot();
        $this->get(route('cart.index'))->assertOk()
            ->assertSee('data-cart-subtotal>37.50 €', false)
            ->assertSee('data-cart-discount>-3.75 €', false);
        $this->getJson(route('checkout.options'))->assertOk()
            ->assertJsonPath('totals.display_discount_total_formatted', '3.75 €')
            ->assertJsonPath('totals.grand_total_formatted', '39.65 €');
    }

    private function addProduct(string $sku, float $price, float $rate, int $quantity = 1): void
    {
        $tax = TaxRate::create(['code' => $sku, 'name' => 'PDV', 'rate' => $rate, 'rate_type' => 'percent', 'is_active' => true]);
        $product = Product::create(['code' => $sku, 'sku' => $sku, 'base_price' => $price, 'tax_rate_id' => $tax->id,
            'stock_qty' => 100, 'weight_kg' => 1, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $sku, 'slug' => strtolower($sku)]);
        $this->assertTrue(app(CartService::class)->add($product, $quantity));
    }
}
