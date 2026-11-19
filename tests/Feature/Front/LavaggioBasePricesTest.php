<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Services\Integrations\Fira\FiraSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LavaggioBasePricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_page_links_to_both_regulated_files_and_prior_fira_publications(): void
    {
        config(['storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'app.locale' => 'hr', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        Http::preventStrayRequests();
        $page = InfoPage::create(['code' => 'client-created-page', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'slug' => 'bazne-cijene', 'title' => 'Bazne cijene', 'body_html' => '<p>Urednička napomena.</p>']);
        $this->get(route('pages.show', 'bazne-cijene'))->assertOk()->assertSee('Urednička napomena.')
            ->assertSee('/cjenici/roba.csv', false)->assertSee('/cjenici/usluge.xml', false)
            ->assertSee(FiraSettingsService::PRICE_LIST_URL.'#arhiva', false);
        config(['storefront.home' => 'watchline']);
        $this->get('/cjenici/roba.csv')->assertNotFound();
        $this->get(route('pages.show', 'bazne-cijene'))->assertOk()->assertDontSee('/cjenici/roba.csv', false);
    }

    public function test_reference_dates_and_promotional_history_are_resolved_from_dated_merchant_evidence(): void
    {
        foreach ([
            ['household', '2024-01-01', '2025-05-02', true, 12.00],
            ['household', '2026-11-16', '2026-11-16', true, 12.00],
            ['other', '2025-08-01', '2026-09-10', true, 14.00],
            ['service', '2026-11-16', '2026-11-16', true, 14.00],
            ['other', '2025-08-01', '2026-09-10', false, 10.00],
            ['household', '2024-01-01', '2026-11-16', false, null],
        ] as [$group, $first, $date, $discounted, $expected]) {
            $product = new Product(['payload' => ['retail_price_evidence' => ['group' => $group,
                'source' => 'Merchant register', 'first_offered_on' => $first, 'price_on' => $date,
                'on_promotion' => $discounted, 'retail_price' => 10, 'lowest_30_before_promotion' => 12, 'previous_retail_price' => 14]]]);
            $this->assertSame($expected, \App\Support\Lavaggio\RetailBasePrice::forProduct($product)['price'] ?? null);
        }
        $unconfirmed = new Product(['sku' => '200009', 'base_price' => 19.99, 'listing_price' => 19.99]);
        $this->assertNull(\App\Support\Lavaggio\RetailBasePrice::forProduct($unconfirmed));
    }

    public function test_same_day_publication_and_retention_from_replacement(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $root = storage_path('framework/testing/lavaggio-publication-'.uniqid());
        $original = storage_path();
        app()->useStoragePath($root);
        $lists = app(\App\Support\Lavaggio\PublishedPriceLists::class);
        $source = $this->mock(\App\Support\Lavaggio\RetailPriceLists::class);
        $row = ['Sredstvo č & ž', '200001', 'Marka', '0.5', 'l', '5.00', 'NE', '10.00', '0123', '', '3.90'];
        $changed = array_replace($row, [5 => '4.00', 6 => 'DA', 7 => '8.00']);
        $source->shouldReceive('goods')->andReturn([$row], [$row], [$changed], [$row], [$row], [$row]);
        $source->shouldReceive('services')->andReturn([['Pranje', '4.00', 'NE', '3.50']]);
        try {
            $this->travelTo(\Carbon\Carbon::parse('2026-10-10 07:00', 'Europe/Zagreb'));
            $lists->publish($source);
            $this->travel(40)->days();
            $this->assertSame(1, $lists->publish($source)['roba']['version']);
            $lists->publish($source);
            $lists->publish($source);
            $this->assertCount(2, $lists->archive());
            $res = $this->get('/cjenici/roba.csv')->assertOk();
            $this->assertStringContainsString('96889385802_ROBA_3_', $res->headers->get('content-disposition'));
            $file = fopen($res->baseResponse->getFile()->getPathname(), 'r');
            $this->assertSame(array_values(\App\Support\Lavaggio\RetailPriceLists::GOODS), fgetcsv($file, escape: '', separator: ';'));
            $this->assertSame('5,00', fgetcsv($file, escape: '', separator: ';')[5]);
            fclose($file);
            $old = $this->get('/cjenici/arhiva/roba/2.xml')->assertOk();
            $xml = simplexml_load_file($old->baseResponse->getFile()->getPathname());
            $this->assertSame('4.00', (string) $xml->roba->maloprodajna_cijena);
            $this->assertSame('0123', (string) $xml->roba->barkod);
            $this->travel(14)->days();
            $lists->publish($source);
            $this->get('/cjenici/arhiva/roba/1.csv')->assertOk();
            $this->travel(2)->days();
            $lists->publish($source);
            $this->get('/cjenici/arhiva/roba/1.csv')->assertNotFound();
            $this->get('/cjenici/roba.xml')->assertOk();
        } finally {
            app()->useStoragePath($original);
            \Illuminate\Support\Facades\File::deleteDirectory($root);
        }
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
