<?php

namespace Tests\Feature\Shipping;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\GiftVoucherService;
use App\Services\Import\WatchlineCheckoutConfigurationService;
use App\Services\Payments\KeksPayService;
use App\Services\Payments\WSPayFormService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\ShippingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WatchlineCheckoutConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private TaxRate $vat;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront.brand' => 'watchline',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'commerce.local_safe_mode' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        CartService::forgetRequestSnapshot();
        $this->vat = TaxRate::create(['code' => 'watchline-checkout-vat', 'name' => 'PDV', 'rate_type' => 'percent',
            'rate' => 25, 'is_active' => true, 'is_default' => true]);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'symbol_position' => 'right',
            'decimal_places' => 2, 'exchange_rate' => 1, 'is_default' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'watchline-oc-status-1', 'name' => 'Nova', 'is_default' => true, 'is_active' => true]);
    }

    public function test_source_setup_replaces_inherited_checkout_once_and_preserves_admin_edits_until_forced(): void
    {
        $inheritedShipping = ShippingMethod::create(['code' => 'mbe_old', 'name' => 'Old tariff', 'is_active' => true]);
        $inheritedPayment = PaymentMethod::updateOrCreate(['code' => 'quote_request'], ['name' => 'Old quote', 'is_active' => true]);
        $service = app(WatchlineCheckoutConfigurationService::class);
        $service->configure($this->snapshot());

        $this->assertFalse($inheritedShipping->fresh()->is_active);
        $this->assertFalse($inheritedPayment->fresh()->is_active);
        $this->assertEqualsCanonicalizing(['gls_home', 'gls_eu', 'pickup', 'boxnow', 'gls_locker'], ShippingMethod::where('is_active', true)->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['bank', 'cod', 'pickup', 'wspay', 'free_checkout'], PaymentMethod::where('is_active', true)->pluck('code')->all());
        $keks = PaymentMethod::where('code', 'keks')->sole();
        $this->assertFalse(data_get($keks->settings, 'source_enabled'));
        $this->assertFalse($keks->is_active);
        $counts = [ShippingMethod::count(), PaymentMethod::count(), GeoZoneCountry::count()];
        $home = ShippingMethod::where('code', 'gls_home')->sole();
        $home->update(['price' => 6.25, 'is_active' => false]);
        $wspay = PaymentMethod::where('code', 'wspay')->sole();
        $wspay->update(['is_active' => false, 'settings' => array_replace($wspay->settings, ['wspay_mode' => 'test', 'wspay_shop_id' => 'admin-test-shop'])]);

        $service->configure($this->snapshot());
        $this->assertSame($counts, [ShippingMethod::count(), PaymentMethod::count(), GeoZoneCountry::count()]);
        $this->assertSame('6.25', $home->fresh()->price);
        $this->assertFalse($home->fresh()->is_active);
        $this->assertFalse($wspay->fresh()->is_active);
        $this->assertSame('admin-test-shop', data_get($wspay->fresh()->settings, 'wspay_shop_id'));

        $service->configure($this->snapshot(), force: true);
        $this->assertSame($counts, [ShippingMethod::count(), PaymentMethod::count(), GeoZoneCountry::count()]);
        $this->assertSame('4.50', $home->fresh()->price);
        $this->assertTrue($home->fresh()->is_active);
        $this->assertSame('live', data_get($wspay->fresh()->settings, 'wspay_mode'));
        $this->assertTrue($wspay->fresh()->is_active);
        $this->assertFalse($keks->fresh()->is_active);
    }

    public static function croatianShippingBoundaries(): array
    {
        return ['below' => [69.99, 4.5], 'at' => [70.0, 0.0], 'above' => [70.01, 0.0]];
    }

    #[DataProvider('croatianShippingBoundaries')]
    public function test_croatian_home_and_gls_locker_use_the_gross_threshold_and_charge_delivery_only_once(float $gross, float $delivery): void
    {
        $this->configure();
        $this->addProduct($gross);
        $summary = app(CartService::class)->summary();
        $checkout = app(CheckoutService::class);
        $methods = $checkout->availableShippingMethods($summary['subtotal_after_discount'], 'HR', null, '10000', 'Zagreb');

        foreach (['gls_home', 'gls_locker'] as $code) {
            $this->assertSame($delivery, $methods->firstWhere('code', $code)?->getAttribute('resolved_price'));
            $totals = $checkout->estimateCheckoutTotals($summary['subtotal'], $summary['discount_total'], $summary['subtotal_after_discount'],
                $summary['tax_total'], $code, 'bank', 'HR', null, '10000', 'HR', null, '10000');
            $this->assertEqualsWithDelta($delivery, $totals['shipping_total'] + $totals['shipping_tax_total'], 0.001);
            $this->assertEqualsWithDelta($gross + $delivery, $totals['grand_total'], 0.001);
        }
    }

    public function test_european_shipping_keeps_only_source_countries_and_the_source_price(): void
    {
        $this->configure();
        $this->addProduct(50);
        $checkout = app(CheckoutService::class);
        foreach (['AT', 'DE', 'SI'] as $country) {
            $methods = $checkout->availableShippingMethods(40, $country)->where('service_type', 'home_delivery')->values();
            $this->assertSame(['gls_eu'], $methods->pluck('code')->all());
            $this->assertSame(19.0, $methods->sole()->getAttribute('resolved_price'));
        }
        $this->assertNotContains('gls_eu', $checkout->availableShippingMethods(40, 'HR')->pluck('code'));
        $this->assertCount(0, $checkout->availableShippingMethods(40, 'CH')->where('service_type', 'home_delivery'));
    }

    public static function sourceDiscountBasis(): array
    {
        return ['current_product_promotion' => ['product', 4.5], 'before_cart_coupon' => ['cart', 0.0]];
    }

    #[DataProvider('sourceDiscountBasis')]
    public function test_shipping_threshold_uses_current_product_prices_before_cart_coupons(string $scope, float $delivery): void
    {
        $this->configure();
        CatalogAction::create(['code' => 'watchline-checkout-half-'.$scope, 'scope' => $scope, 'type' => 'percentage',
            'discount_value' => 50, 'target_type' => 'all', 'audience_type' => 'all', 'is_active' => true,
            'coupon_code' => $scope === 'cart' ? 'WATCHLINE50' : null]);
        $this->addProduct(75);
        $cart = app(CartService::class);
        if ($scope === 'cart') {
            $this->assertTrue($cart->applyCoupon('WATCHLINE50'));
        }
        $summary = $cart->summary();
        $this->assertEqualsWithDelta(37.5, $summary['grand_total'], 0.001);
        $methods = app(CheckoutService::class)->availableShippingMethods($summary['subtotal_after_discount'], 'HR');
        foreach (['gls_home', 'gls_locker'] as $code) {
            $this->assertSame($delivery, $methods->firstWhere('code', $code)?->getAttribute('resolved_price'));
        }
    }

    public function test_source_boxnow_accepts_missing_imported_measurements_without_mutating_products_or_relaxing_generic_configuration(): void
    {
        $this->configure();
        $product = $this->addProduct(50);
        $checkout = app(CheckoutService::class);
        $boxNow = ShippingMethod::where('code', 'boxnow')->sole();
        $this->assertContains('boxnow', $checkout->availableShippingMethods(40, 'HR')->pluck('code'));
        $this->assertEquals(0, $product->fresh()->weight_kg);
        $this->assertEquals(0, $product->fresh()->length_cm);
        $this->assertEquals(0, $product->fresh()->width_cm);
        $this->assertEquals(0, $product->fresh()->height_cm);
        $generic = $boxNow->replicate();
        $generic->code = 'box_now';
        $generic->settings = ['boxnow_partner_id' => 'fixture-widget'];
        $generic->save();
        $this->assertNotContains('box_now', $checkout->availableShippingMethods(40, 'HR')->pluck('code'));
        $calculator = app(ShippingCalculator::class);
        $boxNow->max_weight_kg = 20;
        $product->weight_kg = 21;
        $this->assertNull($calculator->quote($boxNow, collect([['product' => $product, 'quantity' => 1]]), 40));
        $product->weight_kg = 0;
        $product->shipping_labels = ['no_parcel_locker'];
        $this->assertNull($calculator->quote($boxNow, collect([['product' => $product, 'quantity' => 1]]), 40));
        $boxNow->update(['settings' => array_replace($boxNow->settings, ['boxnow_partner_id' => ''])]);
        $this->assertNotContains('boxnow', $checkout->availableShippingMethods(40, 'HR')->pluck('code'));
    }

    public function test_cash_compatibility_and_gateway_readiness_are_respected(): void
    {
        $this->configure();
        $this->addProduct(50);
        $checkout = app(CheckoutService::class);
        $home = ShippingMethod::where('code', 'gls_home')->sole();
        $pickup = ShippingMethod::where('code', 'pickup')->sole();
        $this->assertNotContains('pickup', $checkout->availablePaymentMethods(40, 'HR', shippingMethod: $home)->pluck('code'));
        $this->assertContains('pickup', $checkout->availablePaymentMethods(40, 'HR', shippingMethod: $pickup)->pluck('code'));
        $this->assertNotContains('cod', $checkout->availablePaymentMethods(40, 'HR', shippingMethod: $pickup)->pluck('code'));
        $this->assertContains('wspay', $checkout->availablePaymentMethods(40, 'HR', shippingMethod: $home)->pluck('code'));
        $this->assertNotContains('keks', $checkout->availablePaymentMethods(40, 'HR', shippingMethod: $home)->pluck('code'));
        PaymentMethod::where('code', 'keks')->sole()->update(['is_active' => true]);
        $this->assertContains('keks', $checkout->availablePaymentMethods(40, 'HR', shippingMethod: $home)->pluck('code'));
        foreach (['wspay' => 'wspay_secret_key', 'keks' => 'keks_des_key'] as $code => $requiredKey) {
            $method = PaymentMethod::where('code', $code)->sole();
            $method->update(['settings' => array_replace($method->settings, [$requiredKey => ''])]);
            $this->assertNotContains($code, $checkout->availablePaymentMethods(40, 'HR', shippingMethod: $home)->pluck('code'));
        }
    }

    public static function sourcePaymentMinimums(): array
    {
        return [
            'bank_small_cart_with_paid_delivery' => [2.99, 'gls_home', 'bank', true],
            'bank_total_below_minimum' => [2.99, 'pickup', 'bank', false],
            'bank_gross_total_at_minimum' => [3.0, 'pickup', 'bank', true],
            'cod_zero_items_with_paid_delivery' => [0.0, 'gls_home', 'cod', true],
            'cod_zero_total_pickup' => [0.0, 'pickup', 'cod', false],
            'cod_zero_total_delivery' => [0.0, 'boxnow', 'cod', false],
            'cod_total_at_minimum' => [0.01, 'boxnow', 'cod', true],
        ];
    }

    #[DataProvider('sourcePaymentMinimums')]
    public function test_source_payment_minimums_compare_the_total_including_item_tax_and_selected_shipping(float $gross, string $shippingCode, string $paymentCode, bool $available): void
    {
        $this->configure();
        $this->addProduct($gross);
        $summary = app(CartService::class)->summary();
        $shipping = ShippingMethod::where('code', $shippingCode)->sole();
        $codes = app(CheckoutService::class)->availablePaymentMethods($summary['subtotal_after_discount'], 'HR', shippingMethod: $shipping)->pluck('code')->all();

        $this->assertSame($available, in_array($paymentCode, $codes, true));
    }

    public function test_source_payment_minimum_includes_shipping_tax_at_the_exact_boundary(): void
    {
        $this->configure();
        $this->addProduct(0);
        $shipping = ShippingMethod::where('code', 'gls_home')->sole();
        $shipping->settings = array_replace($shipping->settings, ['tax_rate_percent' => 25]);
        $shipping->price = 2.39;
        $checkout = app(CheckoutService::class);
        $this->assertNotContains('bank', $checkout->availablePaymentMethods(0, 'HR', shippingMethod: $shipping)->pluck('code'));
        $shipping->price = 2.4;
        $this->assertContains('bank', $checkout->availablePaymentMethods(0, 'HR', shippingMethod: $shipping)->pluck('code'));
    }

    public function test_source_payment_minimum_uses_the_remainder_after_a_voucher_and_excludes_payment_fees(): void
    {
        $this->configure();
        $this->addProduct(10);
        $summary = app(CartService::class)->summary();
        $shipping = ShippingMethod::where('code', 'boxnow')->sole();
        $voucher = new GiftVoucher(['initial_amount' => 10, 'balance' => 7, 'recipient_email' => 'voucher@example.test',
            'status' => GiftVoucher::STATUS_ACTIVE, 'issued_at' => now()]);
        $voucher->storeCode('WATCHLINE-MINIMUM-FIXTURE');
        $voucher->save();
        $this->assertTrue(app(GiftVoucherService::class)->applyCode($voucher->code));
        $checkout = app(CheckoutService::class);
        $this->assertContains('bank', $checkout->availablePaymentMethods($summary['subtotal_after_discount'], 'HR', shippingMethod: $shipping)->pluck('code'));
        $voucher->update(['balance' => 7.01]);
        PaymentMethod::where('code', 'bank')->update(['fee_type' => 'fixed', 'fee_value' => 10]);
        $this->assertNotContains('bank', $checkout->availablePaymentMethods($summary['subtotal_after_discount'], 'HR', shippingMethod: $shipping)->pluck('code'));
        $voucher->update(['balance' => 9.99]);
        $this->assertContains('cod', $checkout->availablePaymentMethods($summary['subtotal_after_discount'], 'HR', shippingMethod: $shipping)->pluck('code'));
        $voucher->update(['balance' => 10]);
        $this->assertSame([GiftVoucherService::PAYMENT_CODE], $checkout->availablePaymentMethods($summary['subtotal_after_discount'], 'HR', shippingMethod: $shipping)->pluck('code')->all());
    }

    public function test_source_total_minimum_does_not_change_unmarked_payment_methods(): void
    {
        $this->configure();
        $this->addProduct(2.99);
        PaymentMethod::create(['code' => 'native-bank', 'name' => 'Native bank', 'provider' => 'bank', 'min_subtotal' => 3, 'is_active' => true]);
        $summary = app(CartService::class)->summary();
        $shipping = ShippingMethod::where('code', 'gls_home')->sole();
        $codes = app(CheckoutService::class)->availablePaymentMethods($summary['subtotal_after_discount'], 'HR', shippingMethod: $shipping)->pluck('code');

        $this->assertContains('bank', $codes);
        $this->assertNotContains('native-bank', $codes);
    }

    public function test_free_checkout_is_available_only_for_an_actual_zero_order_including_shipping(): void
    {
        $this->configure();
        $this->addProduct(0);
        $checkout = app(CheckoutService::class);
        $pickup = ShippingMethod::where('code', 'pickup')->sole();
        $home = ShippingMethod::where('code', 'gls_home')->sole();
        $this->assertContains('free_checkout', $checkout->availablePaymentMethods(0, 'HR', shippingMethod: $pickup)->pluck('code'));
        $this->assertNotContains('free_checkout', $checkout->availablePaymentMethods(0.01, 'HR', shippingMethod: $pickup)->pluck('code'));
        $this->assertNotContains('free_checkout', $checkout->availablePaymentMethods(0, 'HR', shippingMethod: $home)->pluck('code'));
    }

    public function test_locker_orders_require_selection_and_keep_the_chosen_location_in_the_order(): void
    {
        $this->configure();
        $product = $this->addProduct(50);
        $checkout = app(CheckoutService::class);
        foreach ([
            ['gls_locker', 'shipping_gls_dpm_id', ['shipping_gls_dpm_id' => 'HR-LOCKER-202', 'shipping_gls_dpm_name' => 'Testni GLS paketomat', 'shipping_gls_dpm_type' => 'parcel-locker'], 'shipping.gls_dpm.id', 'HR-LOCKER-202'],
            ['boxnow', 'shipping_boxnow_locker_id', ['shipping_boxnow_locker_id' => 'BOX-303', 'shipping_boxnow_locker_name' => 'Testni BOX NOW'], 'shipping.boxnow.locker_id', 'BOX-303'],
        ] as [$code, $errorKey, $location, $storedKey, $expected]) {
            $payload = $this->orderPayload($code);
            try {
                $checkout->placeOrder($payload);
                $this->fail('A locker order must require its selected location.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
            $order = $checkout->placeOrder(array_replace($payload, $location));
            $this->assertSame($expected, data_get($order->payload, $storedKey));
            $this->assertSame($code, $order->shipping_method_code);
            $this->assertSame('bank', $order->payment_method_code);
            app(CartService::class)->add($product->fresh(), 1);
        }
    }

    public function test_local_payment_preview_keeps_source_configuration_without_preparing_outbound_gateway_data(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        foreach (['wspay', 'keks'] as $code) {
            $method = PaymentMethod::where('code', $code)->sole();
            $this->assertSame('live', data_get($method->settings, $code.'_mode'));
            $this->assertTrue(data_get($method->settings, 'local_preview_only'));
            $order = Order::create(['order_number' => 'WATCHLINE-PREVIEW-'.$code, 'customer_name' => 'Testni kupac', 'customer_email' => 'checkout@example.test',
                'currency_code' => 'EUR', 'payment_method_code' => $code, 'grand_total' => 50]);
            $result = $code === 'wspay' ? app(WSPayFormService::class)->buildFormData($order) : app(KeksPayService::class)->buildSellData($order);
            $this->assertNull($result);
            $this->assertNull($order->fresh()->paid_at);
            $this->assertNull(data_get($order->fresh()->payload, $code.'.request'));
        }
        Http::assertNothingSent();
    }

    private function configure(): void
    {
        app(WatchlineCheckoutConfigurationService::class)->configure($this->snapshot());
    }

    private function snapshot(): array
    {
        return ['version' => 1, 'language_id' => 2, 'geo_zones' => [
            ['source_id' => 6, 'name' => 'Hrvatska', 'countries' => ['HR']],
            ['source_id' => 5, 'name' => 'EU', 'countries' => ['AT', 'DE', 'SI']],
        ], 'settings' => [
            'xshipping_status' => '1', 'xshipping_name1' => 'GLS dostava', 'xshipping_status1' => '1', 'xshipping_cost1' => '4.5', 'xshipping_free1' => '70', 'xshipping_geo_zone_id1' => '6',
            'xshipping_name2' => 'GLS EU', 'xshipping_status2' => '1', 'xshipping_cost2' => '19,00', 'xshipping_free2' => '', 'xshipping_geo_zone_id2' => '5',
            'xshipping_name3' => 'Osobno preuzimanje Zagreb', 'xshipping_status3' => '1', 'xshipping_cost3' => '0', 'xshipping_geo_zone_id3' => '0',
            'config_address' => 'Testna ulica 1, Zagreb',
            'bank_transfer_status' => '1', 'bank_transfer_total' => '3', 'bank_transfer_bank2' => 'Uplatite na račun iz potvrde narudžbe.',
            'cod_status' => '1', 'cod_total' => '0.01',
            'wspay_status' => '1', 'wspay_test' => '0', 'wspay_merchant' => 'fixture-shop', 'wspay_password' => 'fixture-wspay-key',
            'kekspay_status' => '0', 'kekspay_test' => '0', 'kekspay_cid' => 'fixture-cid', 'kekspay_tid' => 'fixture-tid', 'kekspay_password' => 'fixture-keks-key', 'kekspay_token' => 'fixture-advice-token',
            'free_checkout_status' => '1',
            'boxnow_status' => '1', 'boxnow_cost' => '0', 'boxnow_geo_zone_id' => '6', 'boxnow_widget_partner_id' => 'fixture-widget',
            'boxnow_partner_id' => 'fixture-api-partner', 'boxnow_client_id' => 'fixture-client', 'boxnow_client_secret' => 'fixture-client-key', 'boxnow_origin_location_id' => 'fixture-origin',
        ]];
    }

    private function addProduct(float $gross): Product
    {
        $code = 'watchline-checkout-'.Product::count();
        $product = Product::create(['code' => $code, 'sku' => $code, 'base_price' => $gross, 'stock_qty' => 10,
            'tax_rate_id' => $this->vat->id, 'is_active' => true, 'weight_kg' => 0, 'length_cm' => 0, 'width_cm' => 0, 'height_cm' => 0]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Testni sat', 'slug' => $code]);
        $this->assertTrue(app(CartService::class)->add($product, 1));

        return $product;
    }

    private function orderPayload(string $shippingCode): array
    {
        return ['customer_email' => 'checkout@example.test', 'billing_first_name' => 'Test', 'billing_last_name' => 'Kupac',
            'billing_country_code' => 'HR', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb',
            'shipping_country_code' => 'HR', 'shipping_postal_code' => '10000', 'shipping_city' => 'Zagreb',
            'shipping_method_code' => $shippingCode, 'payment_method_code' => 'bank'];
    }
}
