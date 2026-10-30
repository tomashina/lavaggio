<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Settings\System\StoreSettings;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\ShippingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioFreeShippingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_threshold_updates_notices_and_actual_delivery_prices(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $delivery = $this->shipping('delivery', ['price' => 5]);
        $locker = $this->shipping('locker', ['service_type' => 'parcel_locker', 'price' => 4]);
        $pickup = $this->shipping('store-pickup', ['service_type' => 'pickup', 'carrier' => 'pickup', 'free_over' => null]);
        $inactive = $this->shipping('inactive', ['is_active' => false]);
        $settings = app(SystemSettingsService::class);
        $settings->put('store_brand_name', 'Lavaggio');

        $this->settingsComponent()
            ->set('tab', 'pricing')
            ->assertSet('form.lavaggio_free_shipping_threshold', 50)
            ->assertSee('Besplatna dostava za narudžbe iznad (€)')
            ->set('form.lavaggio_free_shipping_threshold', 75.5)
            ->set('form.store_brand_name', 'Unsaved change')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(75.5, $settings->get('lavaggio_free_shipping_threshold'));
        $this->assertSame('Besplatna dostava za narudžbe iznad 75,5 €', $settings->get('store_announcement_text'));
        $this->assertSame('Lavaggio', $settings->get('store_brand_name'));
        $this->assertSame('75.50', $delivery->fresh()->free_over);
        $this->assertSame('75.50', $locker->fresh()->free_over);
        $this->assertNull($pickup->fresh()->free_over);
        $this->assertSame('50.00', $inactive->fresh()->free_over);

        $calculator = app(ShippingCalculator::class);
        $product = new Product(['shipping_labels' => [], 'weight_kg' => 1, 'length_cm' => 10, 'width_cm' => 10, 'height_cm' => 10]);
        $lines = collect([['product' => $product, 'quantity' => 1, 'line_total' => 75.5]]);
        $this->assertSame(5.0, (float) $calculator->quote($delivery->fresh(), $lines, 75.49)['price']);
        $this->assertSame(0.0, (float) $calculator->quote($delivery->fresh(), $lines, 75.5)['price']);
    }

    public function test_invalid_threshold_is_rejected_and_other_themes_cannot_change_it(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->put('lavaggio_free_shipping_threshold', 50);
        $delivery = $this->shipping('delivery');
        config(['storefront.home' => 'lavaggio']);
        $this->settingsComponent()->set('tab', 'pricing')
            ->set('form.lavaggio_free_shipping_threshold', -5)
            ->call('save')->assertHasErrors('form.lavaggio_free_shipping_threshold');
        $this->assertSame(50, $settings->get('lavaggio_free_shipping_threshold'));
        $this->assertSame('50.00', $delivery->fresh()->free_over);

        config(['storefront.home' => 'auto']);
        $this->settingsComponent()->set('tab', 'pricing')
            ->set('form.lavaggio_free_shipping_threshold', 100)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(50, $settings->get('lavaggio_free_shipping_threshold'));
        $this->assertSame('50.00', $delivery->fresh()->free_over);
    }

    private function settingsComponent(): \Livewire\Features\SupportTesting\Testable
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);

        return Livewire::actingAs($admin)->test(StoreSettings::class);
    }

    private function shipping(string $code, array $overrides = []): ShippingMethod
    {
        return ShippingMethod::query()->create($overrides + [
            'code' => $code, 'name' => $code, 'price' => 5, 'free_over' => 50,
            'carrier' => 'manual', 'service_type' => 'home_delivery',
            'pricing_type' => 'flat', 'is_active' => true,
        ]);
    }
}
