<?php

namespace Tests\Feature\Payments;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\GiftVoucherService;
use App\Services\Import\LibermediaSupplementImportService;
use App\Services\Payments\WSPayFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LibermediaWSPayReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->detectEnvironment(fn () => 'local');
        config(['storefront.brand' => 'libermedia', 'commerce.b2b_only' => false, 'commerce.b2c_only' => true, 'commerce.local_safe_mode' => false]);
    }

    public function test_local_test_wspay_needs_real_configuration_before_both_checkout_flows_offer_it(): void
    {
        $method = $this->method(['wspay_mode' => 'test', 'wspay_shop_id' => '', 'wspay_secret_key' => '']);
        $shipping = ShippingMethod::create(['code' => 'standard', 'name' => 'Dostava', 'price' => 0, 'is_active' => true]);
        $this->assertFalse(app(WSPayFormService::class)->canBeOffered($method));
        $this->assertNotContains('wspay', app(CheckoutService::class)->availablePaymentMethods(50, 'HR', null, null, $shipping)->pluck('code'));
        $this->assertNotContains('wspay', app(GiftVoucherService::class)->purchasePaymentMethods(50)->pluck('code'));
        $method->update(['settings' => ['wspay_mode' => 'test', 'wspay_shop_id' => 'fixture-test-shop', 'wspay_secret_key' => 'fixture-test-secret']]);
        $this->assertContains('wspay', app(CheckoutService::class)->availablePaymentMethods(50, 'HR', null, null, $shipping)->pluck('code'));
        $this->assertContains('wspay', app(GiftVoucherService::class)->purchasePaymentMethods(50)->pluck('code'));
    }

    public function test_local_libermedia_cannot_offer_or_prepare_a_live_wspay_form(): void
    {
        $method = $this->method(['wspay_mode' => 'live', 'wspay_shop_id' => 'fixture-live-shop', 'wspay_secret_key' => 'fixture-live-secret']);
        $order = $this->order();
        $service = app(WSPayFormService::class);
        $this->assertFalse($service->canBeOffered($method));
        $this->assertNull($service->buildFormData($order));
        $this->assertNull($order->fresh()->payload);
        $method->update(['settings' => ['wspay_mode' => 'test', 'wspay_shop_id' => 'fixture-test-shop', 'wspay_secret_key' => 'fixture-test-secret']]);
        $form = $service->buildFormData($order);
        $this->assertSame(WSPayFormService::FORM_URL_TEST, $form['action_url']);
        $this->assertArrayNotHasKey('secret_key', $form);
    }

    public function test_ready_first_sorted_wspay_is_default_in_both_forms_without_replacing_a_previous_bank_selection(): void
    {
        $method = $this->method(['wspay_mode' => 'test', 'wspay_shop_id' => 'fixture-test-shop', 'wspay_secret_key' => 'fixture-test-secret']);
        $method->update(['sort_order' => 0]);
        PaymentMethod::create(['code' => 'bank', 'name' => 'Uplata na račun', 'is_active' => true, 'fee_type' => 'fixed', 'fee_value' => 0, 'sort_order' => 1]);
        $shipping = ShippingMethod::create(['code' => 'standard', 'name' => 'Dostava', 'price' => 0, 'is_active' => true]);
        $product = Product::create(['code' => 'WSPAY-DEFAULT', 'sku' => 'WSPAY-DEFAULT', 'base_price' => 50, 'stock_qty' => 10, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Testni artikl', 'slug' => 'wspay-default']);
        app(CartService::class)->add($product);
        $this->assertSame('wspay', app(CheckoutService::class)->availablePaymentMethods(50, 'HR', null, null, $shipping)->first()->code);
        $this->assertSame('wspay', app(GiftVoucherService::class)->purchasePaymentMethods(50)->first()->code);
        foreach (['checkout.create', 'gift-vouchers.create'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $this->assertSame('wspay', $this->selectedPayment($response->getContent()));
            $previousSelection = $this->withSession(['_old_input' => ['payment_method_code' => 'bank']])->get(route($route))->assertOk();
            $this->assertSame('bank', $this->selectedPayment($previousSelection->getContent()));
            session()->forget('_old_input');
        }
        $this->getJson(route('checkout.options', ['payment_method_code' => 'bank', 'shipping_method_code' => 'standard']))
            ->assertOk()->assertJsonPath('totals.payment_method_code', 'bank');
    }

    public function test_local_live_requires_an_explicit_provider_opt_in_and_prepares_only_the_matching_signed_form(): void
    {
        $settings = ['wspay_mode' => 'live', 'wspay_shop_id' => 'fixture-live-shop', 'wspay_secret_key' => 'fixture-live-secret', 'allow_local_live' => false];
        $method = $this->method($settings);
        $order = $this->order();
        $service = app(WSPayFormService::class);
        $this->assertFalse($service->canBeOffered($method));
        $this->assertNull($service->buildFormData($order));
        $settings['allow_local_live'] = true;
        $method->update(['settings' => $settings]);
        $this->assertTrue($service->canBeOffered($method));
        $this->assertSame('wspay', app(CheckoutService::class)->availablePaymentMethods(50, 'HR')->first()->code);
        $this->assertSame('wspay', app(GiftVoucherService::class)->purchasePaymentMethods(50)->first()->code);
        $form = $service->buildFormData($order);
        $this->assertSame(WSPayFormService::FORM_URL_LIVE, $form['action_url']);
        $this->assertSame(128, strlen($form['signature']));
        $this->assertArrayNotHasKey('secret_key', $form);
        $this->assertNull($order->fresh()->paid_at);
        $settings['allow_local_live'] = 'false';
        $method->update(['settings' => $settings]);
        $this->assertFalse($service->canBeOffered($method));
        $this->assertNull($service->buildFormData($order));
    }

    public function test_the_local_guard_does_not_change_other_brands_or_nonlocal_environments(): void
    {
        $method = $this->method(['wspay_mode' => 'live', 'wspay_shop_id' => 'fixture-shop', 'wspay_secret_key' => 'fixture-secret']);
        config(['storefront.brand' => 'herrera']);
        $this->assertTrue(app(WSPayFormService::class)->canBeOffered($method));
        config(['storefront.brand' => 'libermedia']);
        $this->app->detectEnvironment(fn () => 'testing');
        $this->assertTrue(app(WSPayFormService::class)->canBeOffered($method));
    }

    public function test_source_method_refresh_preserves_admin_wspay_configuration_and_first_position(): void
    {
        $method = $this->method(['wspay_mode' => 'test', 'wspay_form_url' => WSPayFormService::FORM_URL_TEST, 'wspay_shop_id' => 'fixture-test-shop', 'wspay_secret_key' => 'fixture-test-secret']);
        config(['database.connections.wspay_source_fixture' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $source = DB::connection('wspay_source_fixture');
        foreach ([
            'oc_setting' => 'store_id INTEGER, key TEXT, value TEXT',
            'oc_extension' => 'type TEXT, code TEXT',
            'oc_order' => 'order_id INTEGER, payment_code TEXT, payment_method TEXT, shipping_code TEXT, shipping_method TEXT',
            'oc_xshippingpro' => 'id INTEGER, method_data TEXT, sort_order INTEGER',
        ] as $table => $columns) {
            $source->statement('CREATE TABLE '.$table.' ('.$columns.')');
        }
        $source->table('oc_extension')->insert([['type' => 'payment', 'code' => 'wspay'], ['type' => 'payment', 'code' => 'bank_transfer']]);
        foreach (['payment_wspay_status' => '0', 'payment_wspay_sort_order' => '8', 'payment_wspay_test' => '0', 'payment_wspay_merchant' => 'fixture-production-shop', 'payment_wspay_password' => 'fixture-production-secret', 'payment_bank_transfer_status' => '1', 'payment_bank_transfer_sort_order' => '0'] as $key => $value) {
            $source->table('oc_setting')->insert(['store_id' => 0, 'key' => $key, 'value' => $value]);
        }
        $importer = app(LibermediaSupplementImportService::class);
        $importMethods = new \ReflectionMethod($importer, 'methods');
        $importMethods->invoke($importer, $source);
        $method->refresh();
        $this->assertTrue($method->is_active);
        $this->assertSame(0, $method->sort_order);
        $this->assertSame('test', $method->settings['wspay_mode']);
        $this->assertSame(WSPayFormService::FORM_URL_TEST, $method->settings['wspay_form_url']);
        $this->assertSame('fixture-test-secret', $method->settings['wspay_secret_key']);
        $this->assertSame('fixture-test-shop', $method->settings['wspay_shop_id']);
        $this->assertGreaterThan(0, PaymentMethod::where('code', 'bank_transfer')->firstOrFail()->sort_order);
        $this->assertStringNotContainsString('fixture-test-secret', DB::table('libermedia_import_records')->where('entity', 'payment_method')->where('source_id', 'wspay')->value('payload'));
        $method->update(['is_active' => false]);
        $source->table('oc_setting')->where('key', 'payment_wspay_status')->update(['value' => '1']);
        $importMethods->invoke($importer, $source);
        $this->assertFalse($method->fresh()->is_active);
        DB::purge('wspay_source_fixture');
    }

    private function method(array $settings): PaymentMethod
    {
        return PaymentMethod::create(['code' => 'wspay', 'name' => 'Kartično plaćanje (WSPay)', 'provider' => 'wspay', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true, 'settings' => $settings]);
    }

    private function selectedPayment(string $html): string
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($dom))->query('//input[@name="payment_method_code" and @checked]')->item(0)?->getAttribute('value') ?? '';
    }

    private function order(): Order
    {
        return Order::create(['order_number' => 'LOCAL-WSPAY-FIXTURE', 'customer_name' => 'Test', 'customer_email' => 'test@example.test', 'payment_method_code' => 'wspay', 'grand_total' => 50]);
    }
}
