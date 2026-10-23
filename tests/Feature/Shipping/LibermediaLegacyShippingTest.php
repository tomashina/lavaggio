<?php

namespace Tests\Feature\Shipping;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Shipping\ShippingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibermediaLegacyShippingTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_ranges_country_and_escaped_postal_rules_are_respected(): void
    {
        config(['commerce.b2c_only' => true]);
        ShippingMethod::query()->update(['is_active' => false]);
        $p = Product::create(['code' => 'shipping-test', 'sku' => 'shipping-test', 'base_price' => 30, 'is_active' => true]);
        $lines = collect([['product' => $p, 'quantity' => 1, 'line_total' => 30, 'line_tax_total' => 7.5]]);
        $rules = ['rate_type' => 'grand', 'postal' => '51561,\\r\\n20226', 'postal_rule' => 'exclusive', 'ranges' => [['start' => 0, 'end' => 40, 'cost' => 5], ['start' => 40.01, 'end' => 10000000, 'cost' => 0]]];
        $method = ShippingMethod::create(['code' => 'legacy-hr', 'name' => 'Dostava HR', 'is_active' => true, 'settings' => ['destination_country_codes' => ['HR'], 'opencart_xshippingpro' => $rules]]);
        $calculator = app(ShippingCalculator::class);
        $this->assertEquals(5, $calculator->quote($method, $lines, 30)['price']);
        $freeLines = collect([['product' => $p, 'quantity' => 2, 'line_total' => 60, 'line_tax_total' => 15]]);
        $this->assertEquals(0, $calculator->quote($method, $freeLines, 60)['price']);
        $this->mock(CartService::class, fn ($mock) => $mock->shouldReceive('lines')->andReturn($lines));
        $checkout = app(CheckoutService::class);
        $this->assertTrue($checkout->availableShippingMethods(30, 'HR', null, '10000')->contains('id', $method->id));
        $this->assertFalse($checkout->availableShippingMethods(30, 'HR', null, '20226')->contains('id', $method->id));
        $this->assertFalse($checkout->availableShippingMethods(30, 'SI', null, '1000')->contains('id', $method->id));
    }
}
