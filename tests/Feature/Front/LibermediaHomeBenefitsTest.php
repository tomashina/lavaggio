<?php

namespace Tests\Feature\Front;

use App\Models\Content\ContentBlock;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Settings\SystemSettingsService;
use App\Support\FontAwesomeIcon;
use App\Support\LibermediaShippingBenefit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LibermediaHomeBenefitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['app.name' => 'Libermedia', 'storefront.brand' => 'libermedia', 'storefront.theme' => 'herrera', 'storefront.home' => 'libermedia', 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Liber Media']);
    }

    public function test_imported_actual_hr_shipping_range_supplies_the_threshold_and_island_exception(): void
    {
        ShippingMethod::query()->update(['is_active' => false]);
        $method = $this->method([
            'settings' => [
                'destination_country_codes' => ['HR'],
                'opencart_xshippingpro' => [
                    'rate_type' => 'grand', 'postal' => '20226,51561', 'postal_rule' => 'exclusive',
                    'ranges' => [['start' => 0, 'end' => 40, 'cost' => 5], ['start' => 40.01, 'end' => 10000000, 'cost' => 0]],
                ],
            ],
        ]);
        $this->assertSame(['title' => 'Besplatna dostava', 'description' => 'Iznad 40 € u HR, osim otoka'], app(LibermediaShippingBenefit::class)->forCroatia());
        $html = view('front.content-blocks.instances.libermedia-home-benefits')->render();
        foreach (['Besplatna dostava', 'Sigurno plaćanje', 'Zaštita kupca', 'Zdravstvena provjerenost', 'Držimo do sigurnosti vaše djece'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertSame(4, substr_count($html, '<svg'));
        $this->assertStringNotContainsString('<use', $html);
        $this->assertStringNotContainsString('certifikat', $html);

        $settings = $method->settings;
        $settings['opencart_xshippingpro']['ranges'] = [['start' => 0, 'end' => 60, 'cost' => 5], ['start' => 60.01, 'end' => 10000000, 'cost' => 0]];
        $method->update(['settings' => $settings]);
        $this->assertSame('Iznad 60 € u HR, osim otoka', app(LibermediaShippingBenefit::class)->forCroatia()['description']);
    }

    public function test_native_free_shipping_threshold_changes_with_shipping_settings(): void
    {
        ShippingMethod::query()->update(['is_active' => false]);
        $method = $this->method(['free_over' => 55]);
        $this->assertSame('Od 55 € u Hrvatskoj', app(LibermediaShippingBenefit::class)->forCroatia()['description']);
        $method->update(['free_over' => 70.5]);
        $this->assertSame('Od 70,5 € u Hrvatskoj', app(LibermediaShippingBenefit::class)->forCroatia()['description']);
        $method->update(['is_active' => false]);
        $this->assertSame('Dostava', app(LibermediaShippingBenefit::class)->forCroatia()['title']);
    }

    public function test_eu_only_pickup_and_inactive_free_methods_do_not_make_a_croatian_free_shipping_promise(): void
    {
        ShippingMethod::query()->update(['is_active' => false]);
        $this->method(['code' => 'eu', 'free_over' => 10, 'settings' => ['destination_country_codes' => ['SI']]]);
        $this->method(['code' => 'benefits-pickup', 'pricing_type' => 'free', 'service_type' => 'pickup']);
        $this->method(['code' => 'inactive', 'pricing_type' => 'free', 'is_active' => false]);
        $this->assertSame(['title' => 'Dostava', 'description' => 'Provjerite mogućnosti dostave'], app(LibermediaShippingBenefit::class)->forCroatia());
    }

    public function test_four_light_icons_exist_in_the_optimized_pro_sprite(): void
    {
        foreach (['truck-fast', 'credit-card', 'shield-check', 'heart-pulse'] as $icon) {
            $inline = FontAwesomeIcon::inline($icon, 'light');
            $this->assertNotNull($inline);
            $this->assertStringContainsString('<path', $inline['content']);
        }
    }

    public function test_benefits_only_seed_preserves_other_editorial_modules(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $assurance = ContentBlock::query()->create(['code' => 'libermedia-home-assurance', 'name' => 'Sačuvani urednički naslov', 'type' => 'rich_text', 'is_active' => true, 'payload' => ['editorial' => 'keep']]);
        $assurance->translations()->create(['locale' => 'hr', 'body_html' => '<p>Sačuvani sadržaj.</p>']);
        $this->artisan('libermedia:configure-home', ['--benefits-only' => true])->assertSuccessful();
        $benefits = ContentBlock::query()->where('code', 'libermedia-home-benefits')->firstOrFail();
        $this->assertTrue($benefits->is_active);
        $this->assertTrue($benefits->slots()->where('placement', 'home.hero_benefits')->where('is_active', true)->exists());
        $this->assertSame('Sačuvani urednički naslov', $assurance->fresh()->name);
        $this->assertSame(['editorial' => 'keep'], $assurance->fresh()->payload);
        $this->assertSame('<p>Sačuvani sadržaj.</p>', $assurance->translations()->first()->body_html);
        $this->assertSame(2, ContentBlock::query()->count());
        $this->get('/')->assertOk()->assertSee('data-libermedia-home-benefits', false)
            ->assertSee('Zdravstvena provjerenost')->assertSee('Od narudžbe do dostave');
    }

    private function method(array $attributes = []): ShippingMethod
    {
        return ShippingMethod::query()->create(array_merge([
            'code' => 'hr', 'name' => 'Dostava HR', 'service_type' => 'home_delivery', 'pricing_type' => 'flat',
            'price' => 5, 'is_active' => true, 'settings' => ['destination_country_codes' => ['HR']],
        ], $attributes));
    }
}
