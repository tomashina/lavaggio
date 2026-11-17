<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Integrations\Fira\FiraOrderPayload;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LavaggioFiraCheckoutSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        Http::preventStrayRequests();
        CartService::forgetRequestSnapshot();

        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'Nova', 'is_default' => true, 'is_active' => true]);
        TaxRate::query()->create(['code' => 'VAT25', 'name' => 'PDV 25%', 'rate' => 25, 'rate_type' => 'percent', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Dostava', 'price' => 4, 'is_active' => true, 'settings' => ['tax_rate_percent' => 25]]);
        PaymentMethod::query()->create(['code' => 'cod', 'name' => 'Pouzeće', 'provider' => 'cod', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    public function test_real_checkout_snapshot_produces_matching_fira_net_tax_and_gross_without_sending(): void
    {
        $order = $this->checkout(12.50, 2);

        $payload = app(FiraOrderPayload::class)->build($order, 'GOTOVINA');

        $this->assertSame('20.00', $order->items()->sole()->line_total);
        $this->assertSame('5.00', $order->items()->sole()->tax_amount);
        $this->assertSame(10.0, $payload['lineItems'][0]['price']);
        $this->assertSame('200049', $payload['lineItems'][0]['lineItemId']);
        $this->assertSame('200049', $payload['lineItems'][0]['productCode']);
        $this->assertSame(0.25, $payload['lineItems'][0]['taxRate']);
        $this->assertSame(4.0, $payload['lineItems'][1]['price']);
        $this->assertSame(24.0, $payload['netto']);
        $this->assertSame(6.0, $payload['taxValue']);
        $this->assertSame(30.0, $payload['brutto']);
        $this->assertFalse($payload['taxesIncluded']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
        Product::where('code', 'FIRA-CHECKOUT')->update(['sku' => 'CHANGED-LATER']);
        $this->assertSame('200049', app(FiraOrderPayload::class)->build($order->fresh(), 'GOTOVINA')['lineItems'][0]['productCode']);
    }

    public function test_real_checkout_cart_coupon_requires_manual_fira_processing_instead_of_guessing_allocation(): void
    {
        CatalogAction::query()->create([
            'code' => 'FIRA-CART-DISCOUNT', 'scope' => CatalogAction::SCOPE_CART,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 10,
            'target_type' => CatalogAction::TARGET_ALL, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'coupon_code' => 'FIRA10', 'is_active' => true,
        ]);
        $order = $this->checkout(12.50, 2, 'FIRA10');

        // Checkout stores this coupon on order totals, while item snapshots retain pre-coupon values.
        $this->assertSame('2.00', $order->discount_total);
        $this->assertSame('27.50', $order->grand_total);
        $this->assertSame('20.00', $order->items()->sole()->line_total);
        $this->assertSame('5.00', $order->items()->sole()->tax_amount);
        $this->assertPayloadBlockedWithoutSending($order);
    }

    #[DataProvider('roundedTaxQuantities')]
    public function test_real_checkout_per_unit_tax_rounding_cannot_send_inconsistent_fira_header_totals(int $quantity, string $lineTax): void
    {
        $order = $this->checkout(1.46, $quantity);
        $item = $order->items()->sole();

        $this->assertSame($lineTax, $item->tax_amount);
        $this->assertNotSame((float) $item->tax_amount, round((float) $item->line_total * 0.25, 2));
        $this->assertPayloadBlockedWithoutSending($order);
    }

    public static function roundedTaxQuantities(): array
    {
        return ['one-cent difference' => [3, '0.87'], 'three-cent difference' => [10, '2.90']];
    }

    private function checkout(float $grossPrice, int $quantity, ?string $coupon = null): Order
    {
        $product = Product::query()->create([
            'code' => 'FIRA-CHECKOUT', 'sku' => '200049', 'base_price' => $grossPrice,
            'tax_rate_id' => TaxRate::query()->where('code', 'VAT25')->sole()->id,
            'stock_qty' => 1000, 'is_active' => true,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Deterdžent', 'slug' => 'fira-checkout']);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, $quantity));
        if ($coupon !== null) {
            $this->assertTrue($cart->applyCoupon($coupon));
        }

        return app(CheckoutService::class)->placeOrder([
            'customer_first_name' => 'Ogledni', 'customer_last_name' => 'Kupac',
            'customer_email' => 'fixture@example.test', 'customer_phone' => '+38500123456',
            'billing_address_line_1' => 'Barban 70', 'billing_city' => 'Barban', 'billing_postal_code' => '52207', 'billing_country_code' => 'HR',
            'shipping_address_line_1' => 'Barban 70', 'shipping_city' => 'Barban', 'shipping_postal_code' => '52207', 'shipping_country_code' => 'HR',
            'shipping_method_code' => 'standard', 'payment_method_code' => 'cod',
        ]);
    }

    private function assertPayloadBlockedWithoutSending(Order $order): void
    {
        try {
            app(FiraOrderPayload::class)->build($order, 'GOTOVINA');
            $this->fail('An inconsistent checkout snapshot must be processed manually in FIRA.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fira', $exception->errors());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('lavaggio_fira_documents', 0);
    }
}
