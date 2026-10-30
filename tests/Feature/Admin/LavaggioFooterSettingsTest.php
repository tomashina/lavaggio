<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Settings\System\StoreSettings;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioFooterSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_links_and_linkedin_can_be_saved_reordered_and_reloaded_without_changing_existing_footer_fields(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $settings = app(SystemSettingsService::class);
        $existing = [
            'store_brand_name' => 'Lavaggio',
            'store_footer_phone' => '+385 99 123 4567',
            'store_footer_contact_title' => 'Kontakt',
            'store_footer_col_1_title' => 'Ponuda',
            'store_footer_col_1_custom_links' => "Trgovina|/shop\nKompleti|/bundle-artikli",
            'store_footer_bottom_copyright_text' => 'Lavaggio prava',
            'store_social_instagram_url' => 'https://www.instagram.com/lavaggio/',
            'store_footer_social_instagram_enabled' => false,
        ];
        foreach (['store_footer_contact_title', 'store_footer_col_1_title', 'store_footer_col_1_custom_links', 'store_footer_bottom_copyright_text'] as $key) {
            $existing[$key.'_translations'] = ['en' => $existing[$key], 'hr' => $existing[$key]];
        }
        $settings->putMany($existing + [
            'lavaggio_footer_legal_title' => 'Uvjeti',
            'lavaggio_footer_legal_links' => [
                ['label' => 'Kupnja', 'url' => '/page/uvjeti-kupnje'],
                ['label' => 'Kontakt', 'url' => '/contact'],
            ],
        ]);

        $links = "Kontakt|/contact\r\n\r\n  Privatnost | /page/privatnost  \r\nVanjska stranica|https://example.com/legal?locale=hr&source=footer";
        $normalized = "Kontakt|/contact\nPrivatnost|/page/privatnost\nVanjska stranica|https://example.com/legal?locale=hr&source=footer";
        $this->settingsComponent()
            ->set('tab', 'branding')
            ->assertSee('Poveznice i njihov redoslijed')
            ->assertSet('form.lavaggio_footer_legal_links', "Kupnja|/page/uvjeti-kupnje\nKontakt|/contact")
            ->set('form.lavaggio_footer_legal_title', 'Uvjeti i informacije')
            ->set('form.lavaggio_footer_legal_links', $links)
            ->set('form.store_social_linkedin_url', 'https://www.linkedin.com/company/lavaggio/')
            ->set('form.store_footer_social_linkedin_enabled', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('form.lavaggio_footer_legal_links', $normalized);

        $this->assertSame([
            ['label' => 'Kontakt', 'url' => '/contact'],
            ['label' => 'Privatnost', 'url' => '/page/privatnost'],
            ['label' => 'Vanjska stranica', 'url' => 'https://example.com/legal?locale=hr&source=footer'],
        ], $settings->get('lavaggio_footer_legal_links'));
        $this->assertSame('Uvjeti i informacije', $settings->get('lavaggio_footer_legal_title'));
        $this->assertSame('https://www.linkedin.com/company/lavaggio/', $settings->get('store_social_linkedin_url'));
        $this->assertFalse($settings->get('store_footer_social_linkedin_enabled'));
        foreach ($existing as $key => $value) {
            $this->assertSame($value, $settings->get($key), $key);
        }

        $this->settingsComponent()->set('tab', 'branding')
            ->assertSet('form.lavaggio_footer_legal_title', 'Uvjeti i informacije')
            ->assertSet('form.lavaggio_footer_legal_links', $normalized)
            ->assertSet('form.store_social_linkedin_url', 'https://www.linkedin.com/company/lavaggio/')
            ->assertSet('form.store_footer_social_linkedin_enabled', false);
    }

    #[DataProvider('invalidLegalLinks')]
    public function test_invalid_legal_links_are_rejected_without_overwriting_saved_links(string $input): void
    {
        config(['storefront.home' => 'lavaggio']);
        $settings = app(SystemSettingsService::class);
        $links = [['label' => 'Uvjeti', 'url' => '/page/uvjeti']];
        $settings->put('lavaggio_footer_legal_links', $links);

        $this->settingsComponent()->set('tab', 'branding')
            ->set('form.lavaggio_footer_legal_links', $input)
            ->call('save')->assertHasErrors('form.lavaggio_footer_legal_links');

        $this->assertSame($links, $settings->get('lavaggio_footer_legal_links'));
    }

    public static function invalidLegalLinks(): array
    {
        return [
            'missing separator' => ['Uvjeti /page/uvjeti'],
            'missing label' => ['|/page/uvjeti'],
            'script URL' => ['Uvjeti|javascript:alert(1)'],
            'protocol relative URL' => ['Uvjeti|//example.com/legal'],
        ];
    }

    public function test_all_legal_links_can_be_removed_and_linkedin_visibility_changed_independently(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $settings = app(SystemSettingsService::class);
        $settings->putMany([
            'lavaggio_footer_legal_links' => [['label' => 'Uvjeti', 'url' => '/page/uvjeti']],
            'store_social_linkedin_url' => 'https://www.linkedin.com/company/lavaggio/',
            'store_footer_social_linkedin_enabled' => false,
        ]);

        $this->settingsComponent()->set('tab', 'branding')
            ->set('form.lavaggio_footer_legal_links', "\n\n")
            ->set('form.store_footer_social_linkedin_enabled', true)
            ->call('save')->assertHasNoErrors()
            ->assertSet('form.lavaggio_footer_legal_links', '');

        $this->assertSame([], $settings->get('lavaggio_footer_legal_links'));
        $this->assertTrue($settings->get('store_footer_social_linkedin_enabled'));
        $this->assertSame('https://www.linkedin.com/company/lavaggio/', $settings->get('store_social_linkedin_url'));
    }

    public function test_legal_settings_are_not_saved_from_other_themes_or_other_tabs(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->putMany(['lavaggio_footer_legal_title' => 'Uvjeti', 'lavaggio_footer_legal_links' => []]);
        config(['storefront.home' => 'auto']);

        $this->settingsComponent()->set('tab', 'branding')
            ->assertDontSee('Poveznice i njihov redoslijed')
            ->set('form.lavaggio_footer_legal_title', 'Promjena')
            ->set('form.lavaggio_footer_legal_links', 'Kontakt|/contact')
            ->call('save')->assertHasNoErrors();

        config(['storefront.home' => 'lavaggio']);
        $this->settingsComponent()->set('tab', 'seo')
            ->set('form.lavaggio_footer_legal_title', 'Druga promjena')
            ->set('form.lavaggio_footer_legal_links', 'Kontakt|/contact')
            ->call('save')->assertHasNoErrors();

        $this->assertSame('Uvjeti', $settings->get('lavaggio_footer_legal_title'));
        $this->assertSame([], $settings->get('lavaggio_footer_legal_links'));
    }

    private function settingsComponent(): \Livewire\Features\SupportTesting\Testable
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);

        return Livewire::actingAs($admin)->test(StoreSettings::class);
    }
}
