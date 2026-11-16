<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Services\Integrations\Fira\FiraPriceArchiveService;
use App\Services\Integrations\Fira\FiraSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LavaggioBasePricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_base_prices_page_includes_live_archive_and_download_links(): void
    {
        config(['storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'app.locale' => 'hr', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        Http::preventStrayRequests();
        Http::fake([FiraSettingsService::PRICE_LIST_URL => Http::response('<ul><li>16.11.2026. 07:00 <a href="'.FiraSettingsService::PRICE_LIST_URL.'/10614.csv">Preuzmi</a></li></ul>')]);
        $page = InfoPage::create(['code' => 'client-created-page', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'slug' => 'bazne-cijene', 'title' => 'Bazne cijene', 'body_html' => '<p>Urednička napomena.</p>']);
        $this->get(route('pages.show', 'bazne-cijene'))->assertOk()
            ->assertSee('Urednička napomena.')->assertSee('Otvori cjenik i arhivu')
            ->assertSee('16.11.2026. 07:00')->assertSee(FiraSettingsService::PRICE_LIST_URL.'/10614.csv', false)
            ->assertSee(FiraSettingsService::PRICE_LIST_URL.'/cjenik.csv', false)
            ->assertSee(FiraSettingsService::PRICE_LIST_URL.'/cjenik.pdf', false)
            ->assertDontSee('This page has no body content.');
        config(['storefront.home' => 'watchline']);
        $this->get(route('pages.show', 'bazne-cijene'))->assertOk()->assertDontSee(FiraSettingsService::PRICE_LIST_URL, false);
    }

    public function test_archive_filters_untrusted_links_and_keeps_only_dated_fira_csvs(): void
    {
        $base = FiraSettingsService::PRICE_LIST_URL;
        $path = parse_url($base, PHP_URL_PATH);
        $entries = app(FiraPriceArchiveService::class)->parse('<ul>'
            .'<li>16.11.2026. 07:00 <a href="'.$path.'/10614.csv">Preuzmi</a></li>'
            .'<li>15.11.2026. 07:00 <a href="10359.csv">Preuzmi</a></li>'
            .'<li>14.11.2026. 07:00 <a href="https://untrusted.example/1.csv">Preuzmi</a></li>'
            .'<li>14.11.2026. 07:00 <a href="javascript:alert(1)">Preuzmi</a></li>'
            .'<li><a href="100.csv">Bez datuma</a></li></ul>');
        $this->assertCount(2, $entries);
        $this->assertSame($base.'/10614.csv', $entries[0]['url']);
        $this->assertSame('15.11.2026. 07:00', $entries[1]['date']);
    }

    public function test_archive_failure_keeps_direct_download_and_archive_links(): void
    {
        config(['storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'app.locale' => 'hr', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        Http::fake([FiraSettingsService::PRICE_LIST_URL => Http::response('', 503)]);
        $page = InfoPage::create(['code' => 'base-prices', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'slug' => 'bazne-cijene', 'title' => 'Bazne cijene']);
        $this->get(route('pages.show', 'bazne-cijene'))->assertOk()
            ->assertSee(FiraSettingsService::PRICE_LIST_URL.'#arhiva', false)
            ->assertSee(FiraSettingsService::PRICE_LIST_URL.'/cjenik.csv', false);
    }

    public function test_listing_date_migration_is_scoped_and_preserves_amounts(): void
    {
        $product = Product::create(['code' => 'NOV13', 'listed_at' => '2026-11-13', 'listing_price' => 8, 'base_price' => 10]);
        $other = Product::create(['code' => 'OLDER', 'listed_at' => '2026-09-10', 'listing_price' => 5, 'base_price' => 12]);
        $migration = require database_path('migrations/2026_11_16_120000_update_lavaggio_listing_date.php');
        config(['storefront.home' => 'watchline']);
        $migration->up();
        $this->assertSame('2026-11-13', $product->fresh()->listed_at->toDateString());
        config(['storefront.home' => 'lavaggio']);
        $migration->up();
        $migration->up();
        $this->assertSame('2026-11-16', $product->fresh()->listed_at->toDateString());
        $this->assertSame('8.00', $product->fresh()->listing_price);
        $this->assertSame('10.00', $product->fresh()->base_price);
        $this->assertSame('2026-09-10', $other->fresh()->listed_at->toDateString());
    }
}
