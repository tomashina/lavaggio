<?php

namespace Tests\Feature\Front;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StorefrontAnalyticsSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(SystemSettingsService::class)->putMany(['store_analytics_enabled' => true,
            'store_analytics_ga4_measurement_id' => 'G-SYNTHETIC', 'store_cookie_consent_enabled' => true]);
        Route::get('/_analytics-safety-probe', function () {
            $data = [
                'canViewPrices' => true,
                'lines' => [['product' => (object) ['sku' => 'TEST-WATCH', 'id' => 1, 'code' => 'TEST-WATCH'],
                    'translation' => (object) ['name' => 'Synthetic watch'], 'sku' => 'TEST-WATCH', 'unit_price' => 25, 'quantity' => 1]],
                'summary' => ['grand_total' => 25],
            ];

            return view('front.partials.analytics', $data)->render().view('front.partials.analytics-ecommerce', $data)->render();
        })->name('cart.index');
    }

    #[DataProvider('blockedRuntimes')]
    public function test_nonproduction_and_safe_mode_never_emit_trackers_or_ecommerce_payloads(string $environment, bool $safeMode): void
    {
        $this->app['env'] = $environment;
        config(['commerce.local_safe_mode' => $safeMode]);
        app(SystemSettingsService::class)->put('store_cookie_consent_enabled', false);

        $response = $this->get('/_analytics-safety-probe')->assertOk();
        foreach (['googletagmanager.com', 'connect.facebook.net', 'facebook.com/tr', 'ShopMetaPixel', 'ShopAnalytics', 'shop-analytics.js', 'G-SYNTHETIC', 'view_cart', 'TEST-WATCH'] as $marker) {
            $response->assertDontSee($marker, false);
        }
    }

    public static function blockedRuntimes(): array
    {
        return ['staging' => ['staging', false], 'local' => ['local', false], 'testing' => ['testing', false], 'production safe mode' => ['production', true]];
    }

    #[DataProvider('productionFlags')]
    public function test_production_keeps_google_flags_meta_consent_and_real_ecommerce_payloads(bool $googleEnabled, bool $consentEnabled): void
    {
        $this->app['env'] = 'production';
        config(['commerce.local_safe_mode' => false]);
        app(SystemSettingsService::class)->putMany(['store_analytics_enabled' => $googleEnabled, 'store_cookie_consent_enabled' => $consentEnabled]);

        $response = $this->get('/_analytics-safety-probe')->assertOk()
            ->assertSee('window.updateMetaPixelConsentFromCookie', false)
            ->assertSee('connect.facebook.net/en_US/fbevents.js', false)
            ->assertSee('view_cart', false)
            ->assertSee('TEST-WATCH', false);
        if ($googleEnabled) {
            $response->assertSee('googletagmanager.com/gtag/js?id=G-SYNTHETIC', false)
                ->assertSee("analytics_storage: 'denied'", false);
        } else {
            $response->assertDontSee('googletagmanager.com', false);
        }
        if ($consentEnabled) {
            $response->assertDontSee('<noscript>', false);
        } else {
            $response->assertSee('window.cookieMarketingAllowed = true', false)
                ->assertSee('<noscript>', false)
                ->assertSee('facebook.com/tr', false);
        }
    }

    public static function productionFlags(): array
    {
        return ['Google with consent' => [true, true], 'Meta without consent UI' => [false, false], 'Meta with consent' => [false, true]];
    }
}
