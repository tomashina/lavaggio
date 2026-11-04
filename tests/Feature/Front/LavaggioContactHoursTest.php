<?php

namespace Tests\Feature\Front;

use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Database\Seeders\LavaggioStorefrontSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioContactHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_and_both_footer_layouts_share_editable_contact_values_without_rewriting_navigation(): void
    {
        config([
            'app.name' => 'Lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia', 'storefront_cache.enabled' => false,
        ]);
        app()->setLocale('hr');
        $this->seed(LavaggioStorefrontSeeder::class);
        $settings = app(SystemSettingsService::class);
        $navigation = $settings->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY);
        $settings->putMany(['store_footer_phone' => '091 111 22 33', 'store_footer_hours' => 'Pon-Pet: 09h - 17h']);

        $response = $this->get(route('home'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $phones = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-contact-phone-inline ")]');
        $this->assertSame(3, $phones->length);
        foreach ($phones as $phone) {
            $this->assertSame('tel:0911112233', $xpath->query('.//a', $phone)->item(0)->getAttribute('href'));
            $this->assertStringContainsString('091 111 22 33', $phone->textContent);
            $this->assertStringContainsString('Radno vrijeme:', $phone->textContent);
            $this->assertStringContainsString('Pon-Pet: 09h - 17h', $phone->textContent);
            $this->assertStringNotContainsString('(Pon-Pet:', $phone->textContent);
            $this->assertSame(1, $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-contact-hours-icon ")]', $phone)->length);
        }
        $this->assertSame($navigation, $settings->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY));

        $settings->put('store_footer_hours', '');
        $this->get(route('home'))->assertOk()->assertDontSee('Pon-Pet: 09h - 17h');
    }
}
