<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use Database\Seeders\LavaggioStorefrontSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.name' => 'Lavaggio',
            'app.locale' => 'hr',
            'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia',
        ]);
        app()->setLocale('hr');
        $this->seed(LavaggioStorefrontSeeder::class);
    }

    public function test_header_opens_a_single_accessible_search_modal_with_live_suggestions(): void
    {
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('front-theme/styles/lavaggio-search.css', false)
            ->assertSee('data-header-search-modal', false)
            ->assertSee('data-autocomplete-enabled="1"', false)
            ->assertSee('data-autocomplete-endpoint="'.route('search.autocomplete').'"', false);

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query('//*[@id="header-search-panel"]')->length);
        $this->assertSame(1, $xpath->query('//button[@data-header-search-toggle and @aria-controls="header-search-panel" and @aria-expanded="false"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="header-search-panel" and @aria-hidden="true" and @inert]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="header-search-panel"]//button[@data-header-search-close and @type="button"]')->length);
        $this->assertSame(1, $xpath->query('//label[@for="lavaggio-search-input"]')->length);
        $this->assertSame(1, $xpath->query('//*[@id="header-search-panel"]//form[@method="GET" and @action="'.route('shop.index').'"]')->length);
    }

    public function test_live_results_use_visible_products_localized_prices_and_the_same_search_as_the_catalog(): void
    {
        $product = $this->product('lenor-omeksivac', 'Lenor omekšivač', true);
        $this->product('lenor-skriveni', 'Lenor skriveni proizvod', false);
        $this->product('ariel-deterdzent', 'Ariel deterdžent', true);

        $this->getJson(route('search.autocomplete', ['q' => 'Lenor']))->assertOk()
            ->assertJsonPath('groups.products.total', 1)
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $product->id)
            ->assertJsonPath('items.0.name', 'Lenor omekšivač')
            ->assertJsonPath('items.0.url', route('products.show', 'lenor-omeksivac'))
            ->assertJsonPath('items.0.price', '12,55 €')
            ->assertJsonPath('search_url', route('shop.index', ['q' => 'Lenor']));

        $this->get(route('shop.index', ['q' => 'Lenor']))->assertOk()
            ->assertViewHas('products', fn ($products) => $products->pluck('id')->all() === [$product->id]);
    }

    public function test_disabling_autocomplete_preserves_the_native_search_form(): void
    {
        app(SystemSettingsService::class)->put('store_search_autocomplete_enabled', false);
        $this->get(route('home'))->assertOk()
            ->assertSee('data-autocomplete-enabled="0"', false)
            ->assertSee('name="q"', false)
            ->assertSee('action="'.route('shop.index').'"', false);
        $this->getJson(route('search.autocomplete', ['q' => 'Lenor']))->assertNotFound();
    }

    private function product(string $slug, string $name, bool $active): Product
    {
        $product = Product::query()->create([
            'code' => $slug,
            'sku' => strtoupper($slug),
            'base_price' => 12.55,
            'stock_qty' => 5,
            'is_active' => $active,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => $slug]);

        return $product;
    }
}
