<?php

namespace Tests\Feature\Front;

use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LavaggioFooterControlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.name' => 'Lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia', 'storefront_cache.enabled' => false,
            'commerce.b2b_only' => false, 'commerce.b2c_only' => true,
        ]);
        app()->setLocale('hr');
        $settings = [
            'store_brand_name' => 'Lavaggio',
            'store_cookie_consent_enabled' => true,
        ];
        foreach (['tiktok', 'instagram', 'youtube', 'facebook', 'linkedin'] as $network) {
            $settings['store_social_'.$network.'_url'] = 'https://social.example.test/'.$network;
            $settings['store_footer_social_'.$network.'_enabled'] = true;
        }
        app(SystemSettingsService::class)->putMany($settings);
    }

    #[DataProvider('storefrontDevices')]
    public function test_socials_and_working_control_hooks_appear_once_at_the_bottom_of_the_footer(string $userAgent): void
    {
        $response = $this->withHeaders(['User-Agent' => $userAgent])->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $controls = '//footer//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-footer-controls ")]';
        $socials = '//*[contains(concat(" ", normalize-space(@class), " "), " herrera-footer-socials ")]';

        $this->assertSame(1, $xpath->query($controls)->length);
        $this->assertSame(1, $xpath->query('//footer'.$socials)->length);
        $this->assertSame(1, $xpath->query($controls.$socials)->length);
        $this->assertSame(1, $xpath->query($controls.'/following-sibling::*[contains(concat(" ", normalize-space(@class), " "), " store-footer-bottom ")]')->length);
        foreach (['site-footer-links', 'site-footer-mobile-links'] as $container) {
            $this->assertSame(0, $xpath->query('//footer//*[contains(concat(" ", normalize-space(@class), " "), " '.$container.' ")]'.$socials)->length);
        }

        $socialLinks = iterator_to_array($xpath->query($controls.$socials.'/a'));
        $this->assertSame(['TikTok', 'Instagram', 'YouTube', 'Facebook', 'LinkedIn'],
            array_map(fn ($link): string => $link->getAttribute('aria-label'), $socialLinks));
        $this->assertSame(array_map(fn ($network): string => 'https://social.example.test/'.$network,
            ['tiktok', 'instagram', 'youtube', 'facebook', 'linkedin']),
            array_map(fn ($link): string => $link->getAttribute('href'), $socialLinks));
        foreach ($socialLinks as $link) {
            $this->assertSame('', trim($link->textContent));
            $this->assertSame(1, $xpath->query('.//*[local-name()="svg"]', $link)->length);
            $this->assertSame('_blank', $link->getAttribute('target'));
            $this->assertSame('noopener noreferrer', $link->getAttribute('rel'));
        }

        $headerLinks = iterator_to_array($xpath->query('//header//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-header-socials ")]/a'));
        $this->assertSame(array_map(fn ($link): string => $link->getAttribute('href'), $socialLinks),
            array_map(fn ($link): string => $link->getAttribute('href'), $headerLinks));
        $this->assertSame(array_map(fn ($link): string => $link->getAttribute('aria-label'), $socialLinks),
            array_map(fn ($link): string => $link->getAttribute('aria-label'), $headerLinks));
        foreach ($headerLinks as $link) {
            $this->assertSame(1, $xpath->query('.//*[local-name()="svg"]', $link)->length);
            $this->assertSame('_blank', $link->getAttribute('target'));
            $this->assertSame('noopener noreferrer', $link->getAttribute('rel'));
        }

        $this->assertSame(1, $xpath->query('//*[@id="cookie-consent-footer-button"]')->length);
        $this->assertSame(1, $xpath->query($controls.'//*[@id="cookie-consent-footer-button"]')->length);
        $this->assertSame(0, $xpath->query('//*[@id="cookie-consent-floating-button"]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-scroll-to-top]')->length);
        $backToTop = $xpath->query($controls.'//*[@data-scroll-to-top]')->item(0);
        $this->assertNotNull($backToTop);
        $this->assertStringContainsString('lavaggio-footer-icon-button', $backToTop->getAttribute('class'));
        $this->assertStringNotContainsString('scroll-to-top-button', $backToTop->getAttribute('class'));
        $this->assertSame('Povratak na vrh', $backToTop->getAttribute('aria-label'));
        $this->assertSame(1, $xpath->query('//script[contains(@src, "/front-theme/scripts/scroll-to-top.js")]')->length);
    }

    #[DataProvider('storefrontDevices')]
    public function test_disabling_cookie_consent_removes_its_footer_trigger_and_keeps_back_to_top(string $userAgent): void
    {
        app(SystemSettingsService::class)->put('store_cookie_consent_enabled', false);

        $response = $this->withHeaders(['User-Agent' => $userAgent])->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//*[@id="cookie-consent-footer-button" or @id="cookie-consent-floating-button"]')->length);
        $this->assertSame(1, $xpath->query('//footer//*[@data-scroll-to-top]')->length);
        $this->assertSame(1, $xpath->query('//script[contains(@src, "/front-theme/scripts/scroll-to-top.js")]')->length);
    }

    public function test_legal_navigation_renders_its_editable_heading_and_configured_links(): void
    {
        app(SystemSettingsService::class)->putMany([
            'lavaggio_footer_legal_title' => 'Pravila naše trgovine',
            'lavaggio_footer_legal_links' => [
                ['label' => 'Uvjeti kupnje', 'url' => '/page/uvjeti-kupnje'],
                ['label' => 'Zaštita osobnih podataka', 'url' => '/page/privatnost'],
            ],
        ]);

        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $legal = '//footer//nav[contains(concat(" ", normalize-space(@class), " "), " lavaggio-footer-legal ")]';
        $this->assertSame(1, $xpath->query($legal)->length);
        $this->assertSame('lavaggio-footer-legal-title', $xpath->query($legal)->item(0)->getAttribute('aria-labelledby'));
        $this->assertSame('Pravila naše trgovine', trim($xpath->query($legal.'//*[@id="lavaggio-footer-legal-title"]')->item(0)->textContent));
        $links = iterator_to_array($xpath->query($legal.'//a'));
        $this->assertSame(['Uvjeti kupnje', 'Zaštita osobnih podataka'], array_map(fn ($link): string => trim($link->textContent), $links));
        $this->assertSame(['/page/uvjeti-kupnje', '/page/privatnost'], array_map(fn ($link): string => $link->getAttribute('href'), $links));
    }

    public function test_header_and_footer_share_social_settings_even_when_utility_links_are_disabled(): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_announcement_enabled' => false,
            'store_footer_social_tiktok_enabled' => false,
            \App\Services\Front\NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => false,
                'socials' => [
                    ['network' => 'tiktok', 'url' => 'https://old.example.test/tiktok', 'is_active' => true],
                    ['network' => 'instagram', 'url' => 'https://old.example.test/instagram', 'is_active' => true],
                ],
            ],
        ]);

        $xpath = $this->xpath($this->get(route('home'))->assertOk()->getContent());
        foreach (['lavaggio-header-socials', 'herrera-footer-socials'] as $class) {
            $links = iterator_to_array($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]/a'));
            $this->assertSame(['Instagram', 'YouTube', 'Facebook', 'LinkedIn'],
                array_map(fn ($link): string => $link->getAttribute('aria-label'), $links));
            $this->assertSame('https://social.example.test/instagram', $links[0]->getAttribute('href'));
        }
    }

    public static function storefrontDevices(): array
    {
        return [
            'desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36'],
            'mobile' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'],
        ];
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
