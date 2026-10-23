<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\ShippingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatchlineProductPurchaseInformationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'storefront.home' => 'watchline', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'storefront_cache.enabled' => false, 'commerce.b2b_only' => false, 'commerce.b2c_only' => true,
            'commerce.local_safe_mode' => true,
        ]);
        app(SystemSettingsService::class)->put('store_brand_name', 'WATCH LINE AM - Web Shop');
        ShippingMethod::query()->update(['is_active' => false]);
        PaymentMethod::query()->update(['is_active' => false]);
        $product = Product::create(['code' => 'WATCH-INFO', 'is_active' => true, 'stock_qty' => 1, 'base_price' => 20]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sat za provjeru', 'slug' => 'watch-info']);
        Http::fake();
        $this->mock(ShippingCalculator::class)->shouldNotReceive('quote');
    }

    public function test_product_information_shows_all_five_native_methods_and_public_terms_without_a_cart_or_shipping_quote(): void
    {
        $hr = $this->zone('hr', ['HR']);
        $eu = $this->zone('eu', ['AT', 'DE', 'SI']);
        $this->shipping('gls_home', 'GLS dostava', ['geo_zone_id' => $hr->id, 'price' => 4.5, 'free_over' => 70]);
        $this->shipping('gls_eu', 'GLS Shipping', ['geo_zone_id' => $eu->id, 'price' => 19]);
        $this->shipping('pickup', 'Importanne Centar Zagreb', ['carrier' => 'pickup', 'service_type' => 'pickup', 'pricing_type' => 'free', 'price' => 0]);
        $this->shipping('gls_locker', 'GLS paketomat', ['geo_zone_id' => $hr->id, 'service_type' => 'parcel_locker', 'price' => 4.5, 'free_over' => 70]);
        $this->boxNow($hr);

        $response = $this->get(route('products.show', ['slug' => 'watch-info']))->assertOk()
            ->assertDontSee('Trenutno nema aktivnih načina dostave.')
            ->assertSee('GLS dostava')->assertSee('GLS Shipping')->assertSee('Importanne Centar Zagreb')
            ->assertSee('GLS paketomat')->assertSee('BOX NOW paketomat')
            ->assertSee('Odredišta: Hrvatska')->assertSee('Austrija')->assertSee('Njemačka')->assertSee('Slovenija')
            ->assertSee('Cijena dostave: 4.50 €')->assertSee('Cijena dostave: 19.00 €')
            ->assertSee('Besplatno za narudžbe od 70.00 €')
            ->assertSee('Prag besplatne dostave računa se prema vrijednosti artikala nakon akcijskih cijena, a prije kupona.')
            ->assertSessionMissing('front_cart');
        $methods = $response->viewData('shippingMethods');
        $this->assertEqualsCanonicalizing(['gls_home', 'gls_eu', 'pickup', 'gls_locker', 'boxnow'], $methods->pluck('code')->all());
        $this->assertSame(5, substr_count($response->getContent(), 'data-product-shipping-method='));
        $this->assertStringContainsString('Besplatno', $this->methodHtml($response->getContent(), 'boxnow'));
        $this->assertStringContainsString('Besplatno', $this->methodHtml($response->getContent(), 'pickup'));
        Http::assertNothingSent();
    }

    public function test_disabled_or_unconfigured_box_now_is_not_advertised_as_available(): void
    {
        $box = $this->boxNow($this->zone('hr', ['HR']));
        $box->update(['settings' => array_replace($box->settings, ['boxnow_partner_id' => ''])]);
        $this->shipping('disabled', 'Isključena dostava', ['is_active' => false]);

        $this->get(route('products.show', ['slug' => 'watch-info']))->assertOk()
            ->assertDontSee('data-product-shipping-method=', false)
            ->assertDontSee('BOX NOW paketomat')->assertDontSee('Isključena dostava')
            ->assertSee('Trenutno nema aktivnih načina dostave.');
        $this->assertTrue($box->fresh()->is_active);
        Http::assertNothingSent();
    }

    public function test_payment_information_uses_gateway_readiness_without_checkout_only_free_payment(): void
    {
        PaymentMethod::create(['code' => 'bank', 'name' => 'Bankovna uplata', 'provider' => 'bank', 'is_active' => true]);
        PaymentMethod::create(['code' => 'free_checkout', 'name' => 'Narudžba bez naplate', 'provider' => 'free_checkout', 'is_active' => true]);
        PaymentMethod::create(['code' => 'keks', 'name' => 'Nespreman KEKS', 'provider' => 'keks', 'is_active' => true]);
        $card = PaymentMethod::create(['code' => 'wspay', 'name' => 'Kartično plaćanje', 'provider' => 'wspay', 'is_active' => true]);

        $this->get(route('products.show', ['slug' => 'watch-info']))->assertOk()
            ->assertSee('Bankovna uplata')->assertDontSee('Kartično plaćanje')
            ->assertDontSee('Nespreman KEKS')->assertDontSee('Narudžba bez naplate');
        $card->update(['settings' => ['wspay_mode' => 'test', 'wspay_shop_id' => 'test-shop', 'wspay_secret_key' => 'private-test-secret']]);
        $this->get(route('products.show', ['slug' => 'watch-info']))->assertOk()
            ->assertSee('Kartično plaćanje')->assertDontSee('private-test-secret')
            ->assertDontSee('Nespreman KEKS')->assertDontSee('Narudžba bez naplate');
        Http::assertNothingSent();
    }

    private function shipping(string $code, string $name, array $attributes = []): ShippingMethod
    {
        return ShippingMethod::updateOrCreate(['code' => $code], array_replace([
            'code' => $code, 'name' => $name, 'carrier' => 'gls', 'service_type' => 'home_delivery',
            'pricing_type' => 'flat', 'price' => 0, 'is_active' => true, 'sort_order' => 0,
            'missing_measurements_policy' => 'allow',
            'settings' => ['configured_from' => 'watchline-opencart', 'subtotal_basis' => 'before_cart_discount'],
        ], $attributes));
    }

    private function boxNow(GeoZone $zone): ShippingMethod
    {
        return $this->shipping('boxnow', 'BOX NOW paketomat', [
            'carrier' => 'boxnow', 'service_type' => 'parcel_locker', 'geo_zone_id' => $zone->id,
            'allows_fragile' => false, 'allows_oversized' => false, 'allows_heavy' => false,
            'settings' => ['configured_from' => 'watchline-opencart', 'source_method' => 'boxnow',
                'legacy_allow_missing_measurements' => true, 'boxnow_partner_id' => 'test-widget-id'],
        ]);
    }

    private function zone(string $code, array $countries): GeoZone
    {
        $zone = GeoZone::create(['code' => $code, 'name' => $code, 'is_active' => true]);
        foreach ($countries as $country) {
            $zone->countries()->create(['country_code' => $country]);
        }

        return $zone;
    }

    private function methodHtml(string $html, string $code): string
    {
        preg_match('/<article[^>]+data-product-shipping-method="'.preg_quote($code, '/').'"[^>]*>(.*?)<\/article>/s', $html, $matches);

        return $matches[1] ?? '';
    }
}
