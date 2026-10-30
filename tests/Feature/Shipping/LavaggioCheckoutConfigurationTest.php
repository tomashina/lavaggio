<?php

namespace Tests\Feature\Shipping;

use App\Livewire\Admin\Settings\Local\ResourceManager;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\GiftVoucherService;
use App\Services\Import\LavaggioCheckoutConfigurationService;
use App\Services\Payments\BankTransferUpiService;
use App\Services\Payments\EpcQrService;
use App\Services\Payments\Hub3BarcodeService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Hub3Pdf417;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioCheckoutConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'storefront.brand' => 'lavaggio',
            'commerce.b2b_only' => false, 'commerce.b2c_only' => true, 'commerce.local_safe_mode' => true,
            'lavaggio_checkout.bank.receiver_iban' => 'XXXX']);
        app()->setLocale('hr');
        Http::preventStrayRequests();
        Http::fake();
        ShippingMethod::query()->delete();
        PaymentMethod::query()->delete();
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'new', 'name' => 'Nova', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::create(['code' => 'lavaggio-local-delivery', 'name' => 'Dostava', 'price' => 4.90,
            'free_over' => 50, 'is_active' => true, 'settings' => ['local_demo' => true]]);
        app(SystemSettingsService::class)->put('lavaggio_free_shipping_threshold', 50);
        CartService::forgetRequestSnapshot();
    }

    public function test_setup_preserves_existing_tariff_and_later_admin_changes(): void
    {
        $service = app(LavaggioCheckoutConfigurationService::class);
        $service->configure();
        $this->assertEqualsCanonicalizing(['gls_home', 'gls_locker', 'boxnow'], ShippingMethod::where('is_active', true)->pluck('code')->all());
        $this->assertEqualsCanonicalizing(['cod', 'bank'], PaymentMethod::where('is_active', true)->pluck('code')->all());
        foreach (['gls_home', 'gls_locker', 'boxnow'] as $code) {
            $method = ShippingMethod::where('code', $code)->sole();
            $this->assertSame('4.90', $method->price);
            $this->assertSame('50.00', $method->free_over);
        }
        ShippingMethod::where('code', 'gls_home')->update(['price' => 6.75, 'free_over' => 80, 'is_active' => false]);
        $bank = PaymentMethod::where('code', 'bank')->sole();
        $bank->update(['fee_value' => 2, 'settings' => array_replace($bank->settings, ['upi_receiver_iban' => 'HR1210010051863000160'])]);
        $service->configure();
        $this->assertSame(4, ShippingMethod::count());
        $this->assertSame(2, PaymentMethod::count());
        $this->assertSame('6.75', ShippingMethod::where('code', 'gls_home')->value('price'));
        $this->assertSame('HR1210010051863000160', data_get($bank->fresh()->settings, 'upi_receiver_iban'));
        $this->assertSame('2.00', $bank->fresh()->fee_value);
    }

    public function test_all_methods_are_reviewable_locally_and_placeholder_bank_is_blocked_outside_safe_preview(): void
    {
        $this->configureCart();
        $checkout = app(CheckoutService::class);
        $this->assertEqualsCanonicalizing(['gls_home', 'gls_locker', 'boxnow'], $checkout->availableShippingMethods(20, 'HR')->pluck('code')->all());
        $home = ShippingMethod::where('code', 'gls_home')->sole();
        config(['gift_vouchers.enabled' => true]);
        $this->assertContains('bank', $checkout->availablePaymentMethods(20, 'HR', shippingMethod: $home)->pluck('code'));
        $this->assertContains('bank', app(GiftVoucherService::class)->purchasePaymentMethods(20)->pluck('code'));
        config(['commerce.local_safe_mode' => false]);
        $this->assertNotContains('boxnow', $checkout->availableShippingMethods(20, 'HR')->pluck('code'));
        $this->assertNotContains('bank', $checkout->availablePaymentMethods(20, 'HR', shippingMethod: $home)->pluck('code'));
        $this->assertNotContains('bank', app(GiftVoucherService::class)->purchasePaymentMethods(20)->pluck('code'));
        config(['commerce.local_safe_mode' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertNotContains('boxnow', $checkout->availableShippingMethods(20, 'HR')->pluck('code'));
        $this->assertNotContains('bank', $checkout->availablePaymentMethods(20, 'HR', shippingMethod: $home)->pluck('code'));
        $this->assertNotContains('bank', app(GiftVoucherService::class)->purchasePaymentMethods(20)->pluck('code'));
    }

    public function test_admin_can_replace_the_placeholder_iban_and_keep_the_local_generator_settings(): void
    {
        app(LavaggioCheckoutConfigurationService::class)->configure();
        $bank = PaymentMethod::where('code', 'bank')->sole();
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Livewire::actingAs($admin)->test(ResourceManager::class, ['resource' => 'payment-methods', 'editPage' => true, 'recordId' => $bank->id])
            ->assertSet('form.upi_receiver_iban', 'XXXX')
            ->assertSeeHtml('wire:model="form.upi_receiver_iban"')
            ->set('form.upi_receiver_iban', 'HR1210010051863000160')->set('form.fee_value', 1.50)
            ->call('save')->assertHasNoErrors();
        $this->assertSame('HR1210010051863000160', data_get($bank->fresh()->settings, 'upi_receiver_iban'));
        $this->assertTrue(data_get($bank->fresh()->settings, 'bank_local_codes'));
        app(LavaggioCheckoutConfigurationService::class)->configure();
        $this->assertSame('HR1210010051863000160', data_get($bank->fresh()->settings, 'upi_receiver_iban'));
        $this->assertSame('1.50', $bank->fresh()->fee_value);
    }

    public function test_known_weight_and_size_restrictions_remain_in_the_preview(): void
    {
        $product = $this->configureCart();
        $product->update(['weight_kg' => 21]);
        CartService::forgetRequestSnapshot();
        $this->assertNotContains('boxnow', app(CheckoutService::class)->availableShippingMethods(20, 'HR')->pluck('code'));
        $product->update(['weight_kg' => 1, 'length_cm' => 55, 'width_cm' => 50]);
        CartService::forgetRequestSnapshot();
        $this->assertNotContains('boxnow', app(CheckoutService::class)->availableShippingMethods(20, 'HR')->pluck('code'));
        $product->update(['weight_kg' => 1, 'length_cm' => 70]);
        CartService::forgetRequestSnapshot();
        $this->assertNotContains('boxnow', app(CheckoutService::class)->availableShippingMethods(20, 'HR')->pluck('code'));
    }

    public function test_placeholder_order_has_an_obvious_nonpayment_qr_and_no_external_request(): void
    {
        $this->configureCart();
        $order = app(CheckoutService::class)->placeOrder($this->payload());
        $snapshot = app(BankTransferUpiService::class)->ensureForOrder($order);
        $this->assertTrue($snapshot['preview_only']);
        $this->assertTrue($snapshot['qr_preview_only']);
        $this->assertSame('XXXX', $snapshot['receiver_iban']);
        $this->assertStringContainsString('NIJE ZA UPLATU', $snapshot['instructions']);
        $this->assertSame('', $snapshot['qr_image_base64']);
        $this->assertStringStartsWith("\x89PNG", base64_decode($snapshot['epc_qr_image_base64']));
        $this->withSession(['front.checkout.last_order_id' => $order->id])->get(route('checkout.success', $order->order_number))
            ->assertOk()->assertSee('Ogledni QR kod · nije za uplatu')->assertSee('IBAN XXXX');
        Http::assertNothingSent();
    }

    public function test_real_iban_from_admin_settings_generates_both_payment_formats_locally(): void
    {
        $this->configureCart();
        $method = PaymentMethod::where('code', 'bank')->sole();
        $method->update(['settings' => array_replace($method->settings, ['upi_receiver_iban' => 'HR1210010051863000160'])]);
        $order = app(CheckoutService::class)->placeOrder($this->payload());
        $snapshot = app(BankTransferUpiService::class)->ensureForOrder($order);
        $this->assertArrayNotHasKey('qr_preview_only', $snapshot);
        $this->assertStringStartsWith("\x89PNG", base64_decode($snapshot['qr_image_base64']));
        $this->assertStringStartsWith("\x89PNG", base64_decode($snapshot['epc_qr_image_base64']));
        $epc = app(EpcQrService::class)->payload($snapshot);
        $this->assertStringStartsWith("BCD\n002\n1\nSCT\n\nBareda d.o.o.\nHR1210010051863000160\nEUR24.90\nSUPP\n\n", $epc);
        $this->assertStringContainsString('HR00 '.$snapshot['reference'], $epc);
        $longDescription = app(EpcQrService::class)->payload(array_replace($snapshot, ['description' => str_repeat('A', 200)]));
        $this->assertStringEndsWith('HR00 '.$snapshot['reference'], $longDescription);
        $this->assertLessThanOrEqual(331, strlen($epc));
        $hub = app(Hub3BarcodeService::class)->payload($snapshot);
        $this->assertStringStartsWith("HRVHUB30\nEUR\n000000000002490\n", $hub);
        $this->assertSame(14, substr_count($hub, "\n"));
        $renderer = new Hub3Pdf417($hub);
        $rows = $columns = $errorLevel = 0;
        $renderer->getCodewords($rows, $columns, $errorLevel);
        $this->assertSame(9, $columns);
        $this->assertSame(4, $errorLevel);
        $method->update(['settings' => array_replace($method->settings, ['upi_receiver_iban' => 'XXXX'])]);
        $this->assertSame($snapshot, app(BankTransferUpiService::class)->ensureForOrder($order->fresh()));
        Http::assertNothingSent();
    }

    #[DataProvider('invalidLockerSelections')]
    public function test_incomplete_or_wrong_locker_selections_cannot_create_orders(string $method, array $selection, string $error): void
    {
        $this->configureCart();
        try {
            app(CheckoutService::class)->placeOrder(array_replace($this->payload(), ['shipping_method_code' => $method], $selection));
            $this->fail('Invalid locker selection was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($error, $exception->errors());
            $this->assertSame(0, Order::count());
        }
    }

    public static function invalidLockerSelections(): array
    {
        return [
            'missing GLS' => ['gls_locker', [], 'shipping_gls_dpm_id'],
            'BOX selection used for GLS' => ['gls_locker', ['shipping_boxnow_locker_id' => '1234'], 'shipping_gls_dpm_id'],
            'incomplete GLS' => ['gls_locker', ['shipping_gls_dpm_id' => 'HR-1234'], 'shipping_gls_dpm_id'],
            'ParcelShop instead of locker' => ['gls_locker', ['shipping_gls_dpm_id' => 'HR-1234', 'shipping_gls_dpm_type' => 'parcel-shop',
                'shipping_gls_dpm_name' => 'Shop', 'shipping_gls_dpm_address_line_1' => 'Ulica 1', 'shipping_gls_dpm_postal_code' => '10000', 'shipping_gls_dpm_city' => 'Zagreb'], 'shipping_gls_dpm_id'],
            'missing BOX' => ['boxnow', [], 'shipping_boxnow_locker_id'],
            'incomplete BOX' => ['boxnow', ['shipping_boxnow_locker_id' => '1234'], 'shipping_boxnow_locker_id'],
        ];
    }

    public function test_locker_choice_persists_and_checkout_exposes_public_widget_without_a_fake_partner_id(): void
    {
        $this->configureCart();
        $this->get(route('checkout.create'))->assertOk()->assertSee('GLS paketomat')->assertSee('BOX NOW paketomat')
            ->assertSee('Bankovna transakcija')->assertSee('Ogledni prikaz · IBAN XXXX · nije za uplatu')
            ->assertSee('data-boxnow-public-widget="1"', false)->assertSee('data-watchline-boxnow-dialog', false);
        $this->getJson(route('checkout.options'))->assertOk()->assertJsonPath('shipping_methods.2.boxnow_public_widget', true)
            ->assertJsonPath('shipping_methods.2.boxnow_partner_id', '')->assertJsonPath('payment_methods.1.preview_only', true);
        $order = app(CheckoutService::class)->placeOrder(array_replace($this->payload(), [
            'shipping_method_code' => 'boxnow', 'shipping_boxnow_locker_id' => '1234', 'shipping_boxnow_locker_name' => 'Test paketomat',
            'shipping_boxnow_address_line_1' => 'Ulica 1', 'shipping_boxnow_postal_code' => '10000', 'shipping_boxnow_city' => 'Zagreb',
        ]));
        $this->assertSame('1234', data_get($order->payload, 'shipping.boxnow.locker_id'));
        $this->assertSame('Ulica 1', data_get($order->payload, 'shipping.boxnow.address_line_1'));
    }

    private function configureCart(): Product
    {
        app(LavaggioCheckoutConfigurationService::class)->configure();
        $product = Product::create(['code' => 'LAVAGGIO-CHECKOUT', 'sku' => 'LAVAGGIO-CHECKOUT', 'base_price' => 20,
            'stock_qty' => 20, 'is_active' => true, 'is_visible' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Test deterdžent', 'slug' => 'test-deterdzent']);
        $this->assertTrue(app(CartService::class)->add($product, 1));

        return $product;
    }

    private function payload(): array
    {
        return [
            'customer_first_name' => 'Test', 'customer_last_name' => 'Kupac', 'customer_email' => 'kupac@example.test',
            'customer_phone' => '+385911234567', 'billing_first_name' => 'Test', 'billing_last_name' => 'Kupac',
            'billing_address_line_1' => 'Ilica 10', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_country_code' => 'HR',
            'shipping_first_name' => 'Test', 'shipping_last_name' => 'Kupac', 'shipping_address_line_1' => 'Ilica 10',
            'shipping_postal_code' => '10000', 'shipping_city' => 'Zagreb', 'shipping_country_code' => 'HR',
            'shipping_method_code' => 'gls_home', 'payment_method_code' => 'bank',
        ];
    }
}
