<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Integrations\Fira\FiraOrderPayload;
use App\Services\Payments\BankTransferUpiService;
use App\Services\Payments\CorvusPayFormService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame(12.5, $payload['lineItems'][0]['price']);
        $this->assertSame('200049', $payload['lineItems'][0]['lineItemId']);
        $this->assertSame('200049', $payload['lineItems'][0]['productCode']);
        $this->assertSame(0.25, $payload['lineItems'][0]['taxRate']);
        $this->assertSame(5.0, $payload['lineItems'][1]['price']);
        $this->assertSame(24.0, $payload['netto']);
        $this->assertSame(6.0, $payload['taxValue']);
        $this->assertSame(30.0, $payload['brutto']);
        $this->assertTrue($payload['taxesIncluded']);
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
    public function test_euro_cent_rounding_matches_the_amount_paid_for_new_and_older_orders(int $quantity, string $lineTax, string $legacyTax): void
    {
        $order = $this->checkout(1.46, $quantity);
        $item = $order->items()->sole();
        $this->assertSame($lineTax, $item->tax_amount);
        $payload = app(FiraOrderPayload::class)->build($order, 'GOTOVINA');
        $this->assertSame(1.46, $payload['lineItems'][0]['price']);
        $this->assertSame($quantity, $payload['lineItems'][0]['quantity']);
        $this->assertInvoiceAmounts($order, $payload);

        $legacyNet = round(1.46 * $quantity - (float) $legacyTax, 2);
        $item->update(['line_total' => $legacyNet, 'tax_amount' => $legacyTax, 'payload' => null]);
        $order->update(['subtotal' => $legacyNet, 'tax_total' => (float) $legacyTax + 1]);
        $legacy = app(FiraOrderPayload::class)->build($order->fresh(), 'GOTOVINA');
        $this->assertSame($quantity, collect($legacy['lineItems'])->where('productCode', '200049')->sum('quantity'));
        $this->assertInvoiceAmounts($order, $legacy);
        $order->update(['grand_total' => (float) $order->grand_total + 0.01]);
        $this->assertPayloadBlockedWithoutSending($order->fresh());
    }

    public static function roundedTaxQuantities(): array
    {
        return ['one-cent difference' => [3, '0.88', '0.87'], 'two-cent difference' => [10, '2.92', '2.90']];
    }

    public function test_three_pieces_at_the_reported_retail_price_and_multiple_reduced_rate_units_match_the_fira_recalculation(): void
    {
        $order = $this->checkout(8.49, 3);
        $item = $order->items()->sole();
        $this->assertSame('20.38', $item->line_total);
        $this->assertSame('5.09', $item->tax_amount);
        $this->assertSame(25.47, $item->grossLineTotal());
        $this->assertInvoiceAmounts($order, app(FiraOrderPayload::class)->build($order, 'KARTICA'));

        app(CartService::class)->clear();
        TaxRate::where('code', 'VAT25')->sole()->update(['rate' => 13]);
        $order = $this->checkout(8.49, 20);
        $this->assertSame(169.8, $order->items()->sole()->grossLineTotal());
        $payload = app(FiraOrderPayload::class)->build($order, 'KARTICA');
        $productLines = collect($payload['lineItems'])->where('productCode', '200049');
        $this->assertSame(0.13, $productLines->first()['taxRate']);
        $this->assertGreaterThan(1, $productLines->count());
        $this->assertSame(20, $productLines->sum('quantity'));
        $this->assertInvoiceAmounts($order, $payload);
        Http::assertNothingSent();
    }

    private function assertInvoiceAmounts(Order $order, array $payload): void
    {
        $gross = $net = 0;
        foreach ($payload['lineItems'] as $line) {
            $unitCents = (int) round($line['price'] * 100);
            $this->assertEquals($unitCents / 100, $line['price']);
            $lineCents = $unitCents * $line['quantity'];
            $gross += $lineCents;
            $unitNet = round($line['price'] / (1 + $line['taxRate']), 3);
            $this->assertSame($lineCents, (int) round($unitNet * $line['quantity'] * (1 + $line['taxRate']) * 100));
            $net += (int) round($unitNet * $line['quantity'] * 100);
        }
        $this->assertTrue($payload['taxesIncluded']);
        $this->assertEquals($gross / 100, $payload['brutto']);
        $this->assertEquals((float) $order->grand_total, $payload['brutto']);
        $this->assertEquals($net / 100, $payload['netto']);
        $this->assertEquals(($gross - $net) / 100, $payload['taxValue']);
        $this->assertEquals((float) $order->tax_total, $payload['taxValue']);
    }

    public function test_checkout_assigns_a_continuous_number_and_uses_it_in_payment_and_fira(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-17 22:50', 'UTC'));
        DB::beginTransaction();
        $this->assertSame('LAV-20261117-0001', $this->checkout(12.50, 1)->order_number);
        DB::rollBack();
        app(CartService::class)->clear();
        $order = $this->checkout(12.50, 1);
        $this->assertSame('LAV-20261117-0001', $order->order_number);
        $this->assertSame($order->order_number, app(FiraOrderPayload::class)->build($order, 'GOTOVINA')['webshopOrderNumber']);

        $order->update(['payment_method_code' => 'bank_transfer']);
        $bank = app(BankTransferUpiService::class)->ensureForOrder($order);
        $this->assertStringContainsString($order->order_number, $bank['description']);
        $this->assertSame('202611170001', $bank['reference']);
        $this->assertSame($bank, app(BankTransferUpiService::class)->ensureForOrder($order->fresh()));

        PaymentMethod::create(['code' => 'corvus', 'name' => 'Kartica', 'provider' => 'corvus',
            'settings' => ['corvus_store_id' => 'test', 'corvus_secret_key' => 'test-key']]);
        $order->update(['payment_method_code' => 'corvus']);
        $this->assertSame($order->order_number, app(CorvusPayFormService::class)->buildFormData($order)['payload']['order_number']);
        app(CartService::class)->clear();
        $this->travel(20)->minutes();
        $this->assertSame('LAV-20261118-0002', $this->checkout(12.50, 1)->order_number);
        Http::assertNothingSent();
    }

    private function checkout(float $grossPrice, int $quantity, ?string $coupon = null): Order
    {
        $product = Product::query()->firstOrCreate(['code' => 'FIRA-CHECKOUT'], [
            'sku' => '200049', 'base_price' => $grossPrice,
            'tax_rate_id' => TaxRate::query()->where('code', 'VAT25')->sole()->id,
            'stock_qty' => 1000, 'is_active' => true,
        ]);
        $product->translations()->firstOrCreate(['locale' => 'hr'], ['name' => 'Deterdžent', 'slug' => 'fira-checkout']);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, $quantity));
        if ($coupon !== null) {
            $this->assertTrue($cart->applyCoupon($coupon));
        }

        return $this->placeOrder();
    }

    public function test_three_perfumes_cost_exactly_twenty_euros_on_the_card_in_the_cart_and_on_the_order(): void
    {
        $group = CustomerGroup::create(['code' => 'b2c', 'name' => 'Kupci', 'is_default' => true, 'is_active' => true]);
        $bundle = Bundle::create(['title' => 'Tri parfema', 'is_active' => true]);
        foreach (['200032', '200033', '200034'] as $sku) {
            $product = Product::create(['code' => $sku, 'sku' => $sku, 'base_price' => 8.49, 'tax_rate_id' => TaxRate::where('code', 'VAT25')->value('id'),
                'stock_qty' => 20, 'is_active' => true]);
            $product->translations()->create(['locale' => 'hr', 'slug' => 'parfem-'.$sku, 'name' => 'Parfem '.$sku]);
            $bundle->products()->attach($product, ['quantity' => 1]);
        }
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'fixed', 'value' => 5.47]);
        ShippingMethod::where('code', 'standard')->update(['price' => 0]);

        $this->get(route('bundles.index'))->assertOk()->assertSeeText('25,47 €')->assertSeeText('20,00 €')->assertSeeText('5,47 €');
        $this->post(route('bundles.store', $bundle))->assertSessionHasNoErrors();
        $this->get(route('cart.index'))->assertOk()->assertSee('data-cart-discount>-5.47 €', false);
        $this->getJson(route('checkout.options'))->assertOk()
            ->assertJsonPath('totals.grand_total_formatted', '20.00 €')
            ->assertJsonPath('totals.tax_total_formatted', '4.00 €');
        $order = $this->placeOrder();
        $this->assertSame('20.00', $order->grand_total);
        $this->assertEquals(20, $order->items->sum(fn ($item) => $item->grossLineTotal()));
        $this->assertInvoiceAmounts($order, app(FiraOrderPayload::class)->build($order, 'GOTOVINA'));

        app(CartService::class)->clear();
        $this->post(route('bundles.store', $bundle), ['quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame(40.0, app(CartService::class)->summary()['grand_total']);
        app(CartService::class)->add($product, 1);
        $this->assertSame(48.49, app(CartService::class)->summary()['grand_total']);
        $order = $this->placeOrder();
        $this->assertInvoiceAmounts($order, app(FiraOrderPayload::class)->build($order, 'GOTOVINA'));
        Http::assertNothingSent();
    }

    private function placeOrder(): Order
    {
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
