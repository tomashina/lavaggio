<?php

namespace Tests\Feature\Front;

use App\Livewire\Admin\Settings\System\StoreSettings;
use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Front\StorefrontStylesService;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineNoticeBarsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'Watchline', 'app.locale' => 'hr',
            'storefront.home' => 'watchline', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false,
        ]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('store_brand_name', 'Watchline');
    }

    public function test_admin_saved_announcement_renders_once_in_the_upper_bar_and_info_stays_outside_the_sticky_header(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        app(SystemSettingsService::class)->putMany([
            'store_announcement_scroll_enabled' => true,
            'store_announcement_scroll_duration_seconds' => 18,
        ]);

        Livewire::actingAs($admin)->test(StoreSettings::class)
            ->set('tab', 'announcement')
            ->assertDontSee('wire:model="form.store_announcement_scroll_enabled"', false)
            ->assertDontSee('wire:model.live="form.store_announcement_scroll_duration_seconds"', false)
            ->set('form.store_announcement_enabled', true)
            ->set('form.store_announcement_text', 'Posebna Watchline obavijest')
            ->set('form.store_announcement_url', '/newsletter')
            ->set('form.store_announcement_new_tab', true)
            ->set('form.store_announcement_background_color', '#123456')
            ->set('form.store_announcement_text_color', '#fedcba')
            ->set('form.store_benefits_bar_enabled', true)
            ->set('form.store_benefits_bar_item_1', '**Originalni** satovi')
            ->set('form.store_benefits_bar_item_2', 'Osobna podrška')
            ->set('form.store_benefits_bar_item_3', 'Sigurna kupnja')
            ->call('save')->assertHasNoErrors()->assertDispatched('notify');

        Livewire::test(StoreSettings::class)
            ->assertSet('form.store_announcement_enabled', true)
            ->assertSet('form.store_announcement_text', 'Posebna Watchline obavijest')
            ->assertSet('form.store_announcement_url', '/newsletter')
            ->assertSet('form.store_announcement_new_tab', true)
            ->assertSet('form.store_announcement_scroll_enabled', true)
            ->assertSet('form.store_announcement_scroll_duration_seconds', 18)
            ->assertSet('form.store_announcement_background_color', '#123456')
            ->assertSet('form.store_announcement_text_color', '#fedcba')
            ->assertSet('form.store_benefits_bar_enabled', true)
            ->assertSet('form.store_benefits_bar_item_1', '**Originalni** satovi');

        $this->app['auth']->forgetGuards();
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('watchline-notice-bars.css', false)
            ->assertSee('store-benefits-rotator.js', false)
            ->assertSee('data-watchline-topbar-announcement', false)
            ->assertDontSee('herrera-announcement-in-topbar', false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(0, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," store-announcement-shell ")]')->length);
        $this->assertSame(1, $xpath->query('//header//*[contains(concat(" ",normalize-space(@class)," ")," site-top-bar ")]//*[@data-watchline-topbar-announcement]/a[@href="/newsletter" and @target="_blank" and @rel="noopener noreferrer"]')->length);
        $this->assertSame(0, $xpath->query('//header//*[@data-watchline-info-bar]')->length);
        $this->assertSame(1, $xpath->query('//body/div[@data-watchline-info-bar]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-store-benefits-rotator]')->length);
        $this->assertSame(3, $xpath->query('//*[@data-store-benefit-item]')->length);
        $this->assertSame('Originalni', $xpath->query('//*[@data-store-benefit-item]//strong')->item(0)?->textContent);
        $this->assertSame(1, substr_count($response->getContent(), 'Posebna Watchline obavijest'));
        $this->assertFalse(app(StoreSettingsService::class)->announcement()['scroll_enabled']);
    }

    public function test_saved_colors_only_style_the_lower_info_bar_and_ignore_legacy_animation_settings(): void
    {
        $styles = app(StorefrontStylesService::class);
        $oldVersion = $styles->version();
        app(SystemSettingsService::class)->putMany([
            'store_announcement_background_color' => '#123456',
            'store_announcement_text_color' => '#fedcba',
            'store_announcement_scroll_duration_seconds' => 30,
        ]);
        $version = $styles->version();

        $this->assertNotSame($oldVersion, $version);
        $this->get(route('front.storefront.styles', ['v' => $version]))->assertOk()
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private')
            ->assertSee('body.watchline-storefront{--watchline-info-background:#123456;--watchline-info-text:#fedcba;}', false)
            ->assertDontSee('--store-announcement-background-color:#123456', false)
            ->assertDontSee('--store-announcement-text-color:#fedcba', false);
        app(SystemSettingsService::class)->put('store_announcement_scroll_duration_seconds', 36);
        $this->assertSame($version, $styles->version());
        $this->get(route('home'))->assertOk()->assertSee(route('front.storefront.styles', ['v' => $version]), false);
    }

    #[DataProvider('visibilitySettings')]
    public function test_announcement_and_info_bar_can_be_controlled_independently(bool $announcement, bool $benefits, string $text, int $announcementCount, int $benefitsCount): void
    {
        app(SystemSettingsService::class)->putMany([
            'store_announcement_enabled' => $announcement, 'store_announcement_text' => $text,
            'store_announcement_scroll_enabled' => false, 'store_benefits_bar_enabled' => $benefits,
            'store_benefits_bar_item_1' => 'Vaša spremljena prednost',
        ]);
        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        $this->assertSame($announcementCount, $xpath->query('//header//*[@data-watchline-topbar-announcement]')->length);
        $this->assertSame($benefitsCount, $xpath->query('//*[@data-store-benefits-rotator]')->length);
        $this->assertSame(0, $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," store-announcement-bar ") and contains(concat(" ",normalize-space(@class)," ")," is-scrolling ")]')->length);
    }

    public function test_upper_announcement_is_independent_of_contacts_and_socials_and_renders_on_mobile(): void
    {
        app(SystemSettingsService::class)->putMany([
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => [
                'is_enabled' => false,
                'links' => [['label' => 'Skriveni kontakt', 'url' => '/contact']],
                'socials' => [['network' => 'instagram', 'url' => 'https://example.com/social']],
            ],
            'store_announcement_enabled' => true,
            'store_announcement_text' => 'Samostalna gornja obavijest',
        ]);

        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'])
            ->get(route('home'))->assertOk()->assertSee('Samostalna gornja obavijest')
            ->assertDontSee('Skriveni kontakt');
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//header//*[@data-watchline-topbar-announcement]')->length);
        $this->assertSame(0, $xpath->query('//header//a[@href="https://example.com/social"]')->length);
    }

    public function test_hidden_legacy_animation_settings_do_not_block_saving_or_get_overwritten(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        $settings = app(SystemSettingsService::class);
        $settings->putMany([
            'store_announcement_scroll_enabled' => true,
            'store_announcement_scroll_duration_seconds' => 90,
        ]);

        Livewire::actingAs($admin)->test(StoreSettings::class)->set('tab', 'announcement')
            ->set('form.store_announcement_text', 'Nova spremljena poruka')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Nova spremljena poruka', $settings->get('store_announcement_text'));
        $this->assertTrue($settings->get('store_announcement_scroll_enabled'));
        $this->assertSame(90, $settings->get('store_announcement_scroll_duration_seconds'));
    }

    public static function visibilitySettings(): array
    {
        return [
            'announcement only' => [true, false, 'Spremljena obavijest', 1, 0],
            'info only' => [false, true, 'Spremljena obavijest', 0, 1],
            'both disabled' => [false, false, 'Spremljena obavijest', 0, 0],
            'empty announcement' => [true, true, '   ', 0, 1],
        ];
    }

    public function test_watchline_unset_defaults_are_blank_but_explicit_saved_messages_are_preserved(): void
    {
        $settings = app(StoreSettingsService::class);
        $this->assertFalse($settings->announcement()['enabled']);
        $this->assertSame('', $settings->announcement()['text']);
        $this->assertFalse($settings->benefitsBar()['enabled']);
        $this->assertSame([], $settings->benefitsBar()['items']);

        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Livewire::actingAs($admin)->test(StoreSettings::class)
            ->assertSet('form.store_announcement_enabled', false)
            ->assertSet('form.store_announcement_text', '')
            ->assertSet('form.store_benefits_bar_enabled', false)
            ->assertSet('form.store_benefits_bar_item_1', '');

        app(SystemSettingsService::class)->putMany([
            'store_benefits_bar_enabled' => true,
            'store_benefits_bar_item_1' => 'Više od **50 000 proizvoda** u ponudi',
            'store_announcement_enabled' => true,
            'store_announcement_text' => 'Naš spremljeni tekst <script>ne izvršava se</script>',
        ]);
        $this->get(route('home'))->assertOk()->assertSee('50 000 proizvoda')
            ->assertSee('Naš spremljeni tekst &lt;script&gt;ne izvršava se&lt;/script&gt;', false)
            ->assertDontSee('<script>ne izvršava se</script>', false);
    }

    public function test_other_storefronts_keep_their_existing_defaults_and_styles(): void
    {
        config(['storefront.home' => 'libermedia']);
        $settings = app(StoreSettingsService::class);
        $this->assertTrue($settings->announcement()['enabled']);
        $this->assertNotSame('', $settings->announcement()['text']);
        $this->assertTrue($settings->benefitsBar()['enabled']);
        $this->assertCount(3, $settings->benefitsBar()['items']);
        $this->assertStringNotContainsString('--watchline-info-', app(StorefrontStylesService::class)->css());
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Livewire::actingAs($admin)->test(StoreSettings::class)->set('tab', 'announcement')
            ->assertSee('wire:model="form.store_announcement_scroll_enabled"', false)
            ->assertSee('wire:model.live="form.store_announcement_scroll_duration_seconds"', false);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new \DOMXPath($document);
    }
}
