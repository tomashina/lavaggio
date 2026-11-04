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
            $this->assertStringNotContainsString('(Pon-Pet:', $phone->textContent);
        }
        $header = $xpath->query('//header//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-contact-phone-inline ")]')->item(0);
        $this->assertStringContainsString('Pon-Pet: 09h - 17h', $header->textContent);
        $this->assertSame(0, $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-contact-hours-icon ")]', $header)->length);

        foreach (['//div[contains(@class, "site-footer-mobile-links")]/details[1]/div', '//div[contains(concat(" ", normalize-space(@class), " "), " site-footer-contact-details ")]'] as $selector) {
            $contact = $xpath->query($selector)->item(0);
            $this->assertNotNull($contact);
            $this->assertSame(0, $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-contact-phone-inline ")]//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-contact-hours ")]', $contact)->length);
            $lastRow = $xpath->query('./p[last()]', $contact)->item(0);
            $this->assertStringContainsString('Radno vrijeme:', $lastRow->textContent);
            $this->assertStringContainsString('Pon-Pet: 09h - 17h', $lastRow->textContent);
            $this->assertSame(1, $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-contact-hours-icon ")]', $lastRow)->length);
            $this->assertStringContainsString('OIB: 96889385802', $xpath->query('./p[last() - 1]', $contact)->item(0)->textContent);
        }
        $this->assertSame($navigation, $settings->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY));

        $settings->put('store_footer_hours', '');
        $this->get(route('home'))->assertOk()->assertDontSee('Pon-Pet: 09h - 17h');
    }
}
