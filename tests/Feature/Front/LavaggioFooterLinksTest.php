<?php

namespace Tests\Feature\Front;

use App\Models\Content\Page\InfoPage;
use App\Services\Settings\SystemSettingsService;
use Database\Seeders\LavaggioFooterLinksSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LavaggioFooterLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_links_resolve_and_only_neutral_placeholder_pages_are_created(): void
    {
        $this->preview();
        $privacy = $this->page('lavaggio-modx-84', 'zastita-podataka-i-prigovor', 'Zaštita podataka i prigovor', $this->privacyBody());
        $jobs = $this->page('lavaggio-modx-81', 'zaposljavamo', 'Zapošljavamo', '<p>Postojeći sadržaj zaposlenja.</p>');
        $settings = app(SystemSettingsService::class);
        $settings->put('store_footer_col_3_custom_links', 'Postojeća poveznica|/shop');

        app(LavaggioFooterLinksSeeder::class)->run();

        $links = $settings->get(LavaggioFooterLinksSeeder::SETTING_KEY);
        $this->assertCount(10, $links);
        $this->assertSame(8, InfoPage::query()->count());
        foreach ($links as $link) {
            $this->get(explode('#', $link['url'], 2)[0])->assertOk();
        }
        foreach (LavaggioFooterLinksSeeder::PLACEHOLDER_PAGES as [$slug, $title]) {
            $page = InfoPage::query()->where('code', 'lavaggio-shop-'.$slug)->firstOrFail();
            $translation = $page->translations()->where('locale', 'hr')->firstOrFail();
            $this->assertTrue($page->is_active);
            $this->assertTrue($page->published_at->lessThanOrEqualTo(now()));
            $this->assertSame($title, $translation->title);
            $this->assertSame(LavaggioFooterLinksSeeder::PLACEHOLDER_BODY, $translation->body_html);
        }
        $privacyBody = $privacy->translations()->first()->body_html;
        foreach (['zastita-podataka', 'privatnost-i-kolacici', 'podnosenje-prigovora'] as $anchor) {
            $this->assertSame(1, substr_count($privacyBody, 'id="'.$anchor.'"'));
            $privacyBody = str_replace(' id="'.$anchor.'"', '', $privacyBody);
        }
        $this->assertSame($this->privacyBody(), $privacyBody);
        $this->assertSame('<p>Postojeći sadržaj zaposlenja.</p>', $jobs->translations()->first()->body_html);
        $this->assertSame('Postojeća poveznica|/shop', $settings->get('store_footer_col_3_custom_links'));
    }

    public function test_repeated_seeding_preserves_page_edits_and_custom_footer_links(): void
    {
        $this->preview();
        $privacy = $this->page('lavaggio-modx-84', 'zastita-podataka-i-prigovor', 'Zaštita podataka i prigovor', $this->privacyBody());
        $this->page('lavaggio-modx-81', 'zaposljavamo', 'Zapošljavamo', '<p>Zaposlenje.</p>');
        app(LavaggioFooterLinksSeeder::class)->run();
        $page = InfoPage::query()->where('code', 'lavaggio-shop-dostava')->firstOrFail();
        $page->translations()->where('locale', 'hr')->update(['title' => 'Uređeni naslov', 'body_html' => '<p>Uređeni sadržaj.</p>']);
        $page->update(['is_active' => false]);
        $customLinks = [['label' => 'Uređena poveznica', 'url' => '/contact']];
        app(SystemSettingsService::class)->put(LavaggioFooterLinksSeeder::SETTING_KEY, $customLinks);
        $anchoredBody = $privacy->translations()->first()->body_html;

        app(LavaggioFooterLinksSeeder::class)->run();

        $this->assertSame(8, InfoPage::query()->count());
        $this->assertSame('Uređeni naslov', $page->translations()->first()->title);
        $this->assertSame('<p>Uređeni sadržaj.</p>', $page->translations()->first()->body_html);
        $this->assertFalse($page->fresh()->is_active);
        $this->assertSame($customLinks, app(SystemSettingsService::class)->get(LavaggioFooterLinksSeeder::SETTING_KEY));
        $this->assertSame($anchoredBody, $privacy->translations()->first()->body_html);
    }

    public function test_footer_seeder_refuses_nonlocal_environments(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $this->expectException(RuntimeException::class);
        app(LavaggioFooterLinksSeeder::class)->run();
    }

    private function preview(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config([
            'app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false,
        ]);
    }

    private function privacyBody(): string
    {
        return '<p>Postojeći uvod.</p><p><strong> TKO OBRAĐUJE VAŠE OSOBNE PODATKE?</strong></p>'
            .'<p>Postojeća privatnost.</p><p><strong><span>KORIŠTENJE INTERNET KOLAČIĆA (COOKIES)</span></strong></p>'
            .'<p>Postojeći kolačići.</p><p><strong>NAČIN PODNOŠENJA PISANOG PRIGOVORA POTROŠAČA</strong></p><p>Postojeći prigovor.</p>';
    }

    private function page(string $code, string $slug, string $title, string $body): InfoPage
    {
        $page = InfoPage::query()->create(['code' => $code, 'is_active' => true, 'published_at' => now()->subDay()]);
        $page->translations()->create(['locale' => 'hr', 'slug' => $slug, 'title' => $title, 'body_html' => $body]);

        return $page;
    }
}
