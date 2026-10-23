<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Import\WatchlineContentNormalizer;
use App\Services\Import\WatchlineSpecialSchedule;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use App\Support\WatchlineReferencePrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchlineFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchline_retains_the_complete_liber_media_home_layout_with_its_own_records(): void
    {
        $this->app['env'] = 'local';
        $this->watchline();
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Watchline', 'store_brand_logo_path' => 'https://www.watchline.hr/image/catalog/logo-white-blue.svg',
            'store_announcement_enabled' => false, 'store_benefits_bar_enabled' => false,
        ]);
        $category = Category::create(['code' => 'watches', 'scope' => 'catalog', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Satovi', 'slug' => 'satovi']);
        $product = $this->product();
        $product->categories()->attach($category);
        $this->artisan('watchline:configure-home')->assertSuccessful();

        $this->get('/')->assertOk()->assertSee('libermedia-storefront', false)
            ->assertSee('libermedia.css', false)->assertSee('libermedia-home-modules.css', false)
            ->assertSee('data-libermedia-home-hero', false)->assertSee('libermedia-hero-headline', false)
            ->assertSee('data-libermedia-campaigns', false)->assertSee('data-libermedia-kindergarten', false)
            ->assertSee('data-libermedia-creative-products', false)->assertSee('data-libermedia-assurance', false)
            ->assertSee('data-libermedia-home-benefits', false)->assertSee('https://www.watchline.hr/image/catalog/watch.jpg', false)
            ->assertSee('https://www.watchline.hr/image/catalog/logo-white-blue.svg', false)
            ->assertDontSee('Didaktičke igračke')->assertDontSee('schoolbag-unicorn.webp', false)
            ->assertDontSee('data-herrera-home-registration', false);
    }

    public function test_source_special_priority_is_preserved_across_overlapping_date_periods(): void
    {
        $schedule = app(WatchlineSpecialSchedule::class)->segments([
            (object) ['id' => 1, 'priority' => 1, 'price' => 80, 'starts_at' => null, 'ends_at' => null],
            (object) ['id' => 2, 'priority' => 2, 'price' => 50, 'starts_at' => null, 'ends_at' => null],
            (object) ['id' => 3, 'priority' => 0, 'price' => 90, 'starts_at' => '2026-10-25 00:00:00', 'ends_at' => '2026-10-27 00:00:00'],
        ]);

        $this->assertSame([1, 3, 1], array_map(fn ($row) => $row['special']->id, $schedule));
        $this->assertNull($schedule[0]['starts_at']);
        $this->assertSame('2026-10-25 00:00:00', $schedule[0]['ends_at']);
        $this->assertSame('2026-10-25 00:00:01', $schedule[1]['starts_at']);
        $this->assertSame('2026-10-26 23:59:59', $schedule[1]['ends_at']);
        $this->assertSame('2026-10-27 00:00:00', $schedule[2]['starts_at']);
        $this->assertNull($schedule[2]['ends_at']);
    }

    public function test_imported_content_uses_live_images_and_resolvable_local_links_idempotently(): void
    {
        $normalizer = app(WatchlineContentNormalizer::class);
        $html = '<p><img src="/image/catalog/a b.jpg"><a href="https://www.watchline.hr/index.php?route=information/contact">Kontakt</a><a href="https://www.watchline.hr/index.php?route=account/register">Registracija</a><a href="https://example.com/details">Partner</a></p>';
        $normalized = $normalizer->normalize($html);

        $this->assertStringContainsString('src="https://www.watchline.hr/image/catalog/a%20b.jpg"', $normalized);
        $this->assertStringContainsString('href="/contact"', $normalized);
        $this->assertStringContainsString('href="/auth/register"', $normalized);
        $this->assertStringContainsString('href="https://example.com/details"', $normalized);
        $this->assertSame($normalized, $normalizer->normalize($normalized));
    }

    public function test_source_dated_price_is_displayed_without_inventing_a_thirty_day_minimum(): void
    {
        $this->watchline();
        $product = $this->product();
        $product->update(['payload' => $product->payload + ['watchline_anchor_price' => [
            'gross_price' => '120.0000', 'currency_code' => 'EUR', 'reference_date' => '2025-05-02', 'verification_status' => 'confirmed',
        ]]]);
        $action = CatalogAction::create([
            'code' => 'watchline-test-special', 'scope' => 'product', 'type' => 'fixed_amount', 'discount_value' => 10,
            'target_type' => 'product', 'audience_type' => 'all', 'is_active' => true,
        ]);
        $action->targets()->create(['target_type' => 'product', 'target_id' => $product->id]);
        $price = app(ProductPricePresentationService::class)->forProduct($product);

        $this->assertSame(90.0, $price['current_gross']);
        $this->assertNull($price['lowest_30_days_gross']);
        $this->assertSame('Cijena na 2. 5. 2025.: 120,00 €', WatchlineReferencePrice::text($product));
        $this->get(route('products.show', ['slug' => 'watchline-test']))->assertOk()
            ->assertSee('Cijena na 2. 5. 2025.: 120,00 €')->assertDontSee('Najniža cijena u prethodnih 30 dana');
    }

    private function watchline(): void
    {
        config([
            'app.name' => 'Watchline', 'storefront.theme' => 'herrera', 'storefront.home' => 'watchline',
            'storefront.brand' => 'libermedia', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
        ]);
    }

    private function product(): Product
    {
        $product = Product::create([
            'code' => 'watchline-test', 'sku' => 'WATCHLINE-TEST', 'base_price' => 100, 'is_active' => true, 'stock_qty' => 3,
            'payload' => ['opencart' => ['product_id' => 42, 'image' => 'catalog/watch.jpg']],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Watchline model', 'slug' => 'watchline-test']);

        return $product;
    }
}
