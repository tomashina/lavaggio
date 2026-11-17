<?php

namespace Tests\Feature\Shipping;

use App\Livewire\Admin\Settings\System\StoreSettings;
use App\Livewire\Admin\Shipping\ShippingManager;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Integrations\Fira\FiraOrderPayload;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\ShippingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioShippingTariffsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        Http::preventStrayRequests();
        app()->setLocale('hr');
        CartService::forgetRequestSnapshot();
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'new', 'name' => 'Nova', 'is_default' => true, 'is_active' => true]);
        TaxRate::create(['code' => 'VAT25', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_default' => true, 'is_active' => true]);
        PaymentMethod::create(['code' => 'cod', 'name' => 'Pouzeće', 'is_active' => true]);
        ShippingMethod::query()->delete();
        ShippingMethod::create(['code' => 'boxnow', 'name' => 'BOX NOW', 'is_active' => true, 'price' => 0,
            'max_length_cm' => 60, 'max_width_cm' => 45, 'max_height_cm' => 36,
            'allows_fragile' => false, 'allows_oversized' => false, 'allows_heavy' => false,
            'settings' => ['configured_from' => 'lavaggio-checkout', 'boxnow_public_widget' => true, 'boxnow_partner_id' => 'keep-partner']]);
        (require database_path('migrations/2026_11_17_130000_align_lavaggio_shipping_tariffs.php'))->up();
    }

    #[DataProvider('tariffs')]
    public function test_checkout_and_fira_use_admin_shipping_identifiers_and_correct_net_vat_and_gross(string $code, string $sku, float $weight, float $net, float $vat, float $gross): void
    {
        $product = $this->cartProduct(50, $weight);
        $checkout = app(CheckoutService::class);
        $method = $checkout->availableShippingMethods(40, 'HR')->firstWhere('code', $code);
        $this->assertNotNull($method);
        $this->assertSame($net, (float) $method->resolved_price);
        $this->assertSame($gross, (float) $method->resolved_display_price);
        $order = $checkout->placeOrder([
            'customer_first_name' => 'Test', 'customer_last_name' => 'Kupac', 'customer_email' => 'test@example.test',
            'billing_address_line_1' => 'Barban 70', 'billing_city' => 'Barban', 'billing_postal_code' => '52207', 'billing_country_code' => 'HR',
            'shipping_address_line_1' => 'Barban 70', 'shipping_city' => 'Barban', 'shipping_postal_code' => '52207', 'shipping_country_code' => 'HR',
            'shipping_method_code' => $code, 'payment_method_code' => 'cod',
            'shipping_boxnow_locker_id' => '123', 'shipping_boxnow_locker_name' => 'Paketomat', 'shipping_boxnow_address_line_1' => 'Adresa 1',
            'shipping_boxnow_postal_code' => '52207', 'shipping_boxnow_city' => 'Barban',
            'shipping_gls_dpm_id' => 'HR123',
        ]);
        $this->assertSame($net, (float) $order->shipping_total);
        $this->assertSame($vat, data_get($order->payload, 'shipping.tax_total'));
        $this->assertSame(round(50 + $gross, 2), (float) $order->grand_total);
        // Changes in admin after checkout must not change a stored order's FIRA mapping or amounts.
        $method->fresh()->update(['price' => 100, 'settings' => [...$method->settings, 'fira_product_code' => 'CHANGED', 'fira_kpd_code' => '01.01.01']]);
        $payload = app(FiraOrderPayload::class)->build($order, 'GOTOVINA');
        $line = $payload['lineItems'][1];
        $this->assertSame($sku, $line['productCode']);
        $this->assertSame($sku, $line['lineItemId']);
        $this->assertSame('53.20.09', $line['kpdCode']);
        $this->assertSame($net, $line['price']);
        $this->assertSame(0.25, $line['taxRate']);
        $this->assertSame(round(50 + $gross, 2), $payload['brutto']);
        Http::assertNothingSent();
    }

    public static function tariffs(): array
    {
        return [
            ['gls_home', '100001', 1, 4.72, 1.18, 5.90],
            ['gls_locker', '100002', 1, 3.92, 0.98, 4.90],
            ['boxnow', '100003', 1, 3.12, 0.78, 3.90],
            ['gls_heavy', '100004', 60, 19.92, 4.98, 24.90],
            ['pallet', '100005', 100, 79.92, 19.98, 99.90],
        ];
    }

    #[DataProvider('weights')]
    public function test_weight_boundaries_select_the_correct_services(float $weight, array $expected): void
    {
        $this->cartProduct(50, $weight);
        $this->assertEqualsCanonicalizing($expected, app(CheckoutService::class)->availableShippingMethods(40, 'HR')->pluck('code')->all());
    }

    public static function weights(): array
    {
        return [[20, ['gls_home', 'gls_locker', 'boxnow']], [20.001, ['gls_home']], [40, ['gls_home']],
            [40.001, ['gls_heavy']], [80, ['gls_heavy']], [80.001, ['pallet']]];
    }

    public function test_known_weight_allows_delivery_without_dimensions_but_unknown_weight_does_not(): void
    {
        $product = $this->cartProduct(50, 1);
        $product->update(['length_cm' => null, 'width_cm' => null, 'height_cm' => null]);
        CartService::forgetRequestSnapshot();
        $this->assertEqualsCanonicalizing(['gls_home', 'gls_locker', 'boxnow'], app(CheckoutService::class)->availableShippingMethods(40, 'HR')->pluck('code')->all());
        $product->update(['weight_kg' => null]);
        CartService::forgetRequestSnapshot();
        $this->assertCount(0, app(CheckoutService::class)->availableShippingMethods(40, 'HR'));
    }

    public function test_free_shipping_uses_gross_cart_value_and_strict_seventy_euro_threshold(): void
    {
        $product = $this->cartProduct(70, 1);
        foreach (app(CheckoutService::class)->availableShippingMethods(56, 'HR') as $method) {
            $this->assertGreaterThan(0, $method->resolved_price);
        }
        $product->update(['base_price' => 70.01]);
        CartService::forgetRequestSnapshot();
        foreach (app(CheckoutService::class)->availableShippingMethods(56.01, 'HR') as $method) {
            $this->assertSame(0.0, $method->resolved_price);
        }
        $this->assertSame('keep-partner', data_get(ShippingMethod::where('code', 'boxnow')->sole()->settings, 'boxnow_partner_id'));
    }

    public function test_heavy_services_remain_chargeable_after_global_admin_threshold_change_and_free_product_labels(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Livewire::actingAs($admin)->test(StoreSettings::class)->set('tab', 'pricing')
            ->set('form.lavaggio_free_shipping_threshold', 80)->call('save')->assertHasNoErrors();
        foreach (['gls_heavy' => [60, 19.92], 'pallet' => [100, 79.92]] as $code => [$weight, $net]) {
            $method = ShippingMethod::where('code', $code)->sole();
            $this->assertNull($method->free_over);
            $product = new Product(['weight_kg' => $weight, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10, 'shipping_labels' => ['free_shipping']]);
            $quote = app(ShippingCalculator::class)->quote($method, collect([['product' => $product, 'quantity' => 1]]), 1000);
            $this->assertSame($net, $quote['price']);
        }
        $this->assertSame('80.00', ShippingMethod::where('code', 'gls_home')->sole()->free_over);
    }

    public function test_shipping_admin_can_edit_all_tariff_and_fira_settings(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        $method = ShippingMethod::where('code', 'gls_home')->sole();
        Livewire::actingAs($admin)->test(ShippingManager::class, ['editPage' => true, 'recordId' => $method->id])
            ->assertSee('FIRA SKU usluge dostave')->assertSet('form.fira_product_code', '100001')
            ->set('form.price', 8)->set('form.tax_rate_percent', 25)->set('form.fira_product_code', 'CUSTOM-SKU')
            ->set('form.fira_kpd_code', '53.20.10')->set('form.max_weight_kg', 35)
            ->set('form.free_shipping_disabled', true)->call('save')->assertHasNoErrors();
        $method->refresh();
        $this->assertSame('8.00', $method->price);
        $this->assertSame('35.000', $method->max_weight_kg);
        $this->assertNull($method->free_over);
        $this->assertSame('CUSTOM-SKU', data_get($method->settings, 'fira_product_code'));
        $this->assertSame('53.20.10', data_get($method->settings, 'fira_kpd_code'));
        $this->cartProduct(50, 1);
        $this->assertSame(10.0, (float) app(CheckoutService::class)->availableShippingMethods(40, 'HR')->firstWhere('code', 'gls_home')->resolved_display_price);
    }

    private function cartProduct(float $price, float $weight): Product
    {
        $product = Product::create(['code' => 'SHIPPING-TEST', 'base_price' => $price, 'tax_rate_id' => TaxRate::where('code', 'VAT25')->sole()->id,
            'stock_qty' => 100, 'is_active' => true, 'weight_kg' => $weight, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Deterdžent', 'slug' => 'shipping-test']);
        $this->assertTrue(app(CartService::class)->add($product, 1));

        return $product;
    }
}
