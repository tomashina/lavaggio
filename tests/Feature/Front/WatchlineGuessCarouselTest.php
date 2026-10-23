<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Front\WatchlineHeroService;
use App\Services\Settings\SystemSettingsService;
use App\Support\WatchlineHomeContent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchlineGuessCarouselTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $guess;

    private Category $watches;

    private Category $women;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.name' => 'Watchline', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.theme' => 'herrera', 'storefront.home' => 'watchline', 'storefront.brand' => 'watchline',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
        ]);
        $this->guess = $this->manufacturer('guess', 'GUESS', 'guess-satovi');
        $this->watches = $this->category('satovi', 'Satovi');
        $this->women = $this->category('zenski-satovi', 'Ženski satovi', $this->watches);
        Category::fixTree();
    }

    public function test_real_guess_brand_selects_unique_active_stocked_products_with_usable_links_and_images(): void
    {
        // In the imported shop guess-satovi is a manufacturer slug, not a category.
        $older = $this->product('guess-older', ['payload' => ['opencart' => ['image' => 'catalog/guess-older.jpg', 'date_added' => '2026-10-01 10:00:00']]]);
        $newer = $this->product('guess-newer', ['payload' => ['opencart' => ['image' => 'catalog/guess-newer.jpg', 'date_added' => '2026-10-20 10:00:00']]]);
        $newer->categories()->attach($this->women);
        $other = $this->manufacturer('other', 'Another brand', 'another-brand');
        $this->product('wrong-manufacturer', ['manufacturer_id' => $other->id]);
        $this->product('out-of-stock', ['stock_qty' => 0]);
        $this->product('inactive', ['is_active' => false]);
        $this->product('missing-image', ['payload' => ['opencart' => ['image' => 'javascript:invalid']]]);
        $this->product('missing-translation')->translations()->delete();
        $this->product('missing-slug')->translations()->update(['slug' => '']);

        $content = app(WatchlineHomeContent::class);
        $ids = $content->guessProducts()->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame([$newer->id], $content->guessProducts(1)->pluck('id')->all());
        $this->assertSame(route('manufacturers.show', ['slug' => 'guess-satovi'], false), $content->guessCategoryUrl());
        $this->assertDatabaseMissing('category_translations', ['slug' => 'guess-satovi']);
    }

    public function test_home_shows_guess_after_cluse_with_local_brand_browse_and_unique_product_cards(): void
    {
        $this->app['env'] = 'local';
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Watchline', 'store_announcement_enabled' => false, 'store_benefits_bar_enabled' => false]);
        $first = $this->product('guess-first');
        $second = $this->product('guess-second');
        $second->categories()->attach($this->women);
        $cluse = $this->manufacturer('cluse', 'CLUSE', 'cluse');
        $cluseProduct = $this->product('cluse-fixture', ['manufacturer_id' => $cluse->id]);
        $hero = $this->mock(WatchlineHeroService::class);
        $hero->shouldReceive('products')->andReturn(new Collection([$cluseProduct]));
        $hero->shouldReceive('rankedProductIds')->andReturn(collect([$cluseProduct->id]));
        $this->artisan('watchline:configure-home')->assertSuccessful();

        $response = $this->get('/')->assertOk()
            ->assertSee('data-watchline-widget="watchline-home-guess"', false)
            ->assertSeeInOrder(['data-watchline-cluse-products', 'data-watchline-guess-products'], false)
            ->assertSee('Novo u ponudi')->assertSee('GUESS satovi');
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $sections = $xpath->query('//*[@data-watchline-guess-products]');
        $this->assertCount(1, $sections);
        $links = $xpath->query('.//a[contains(@class,"herrera-home-products-link")]', $sections->item(0));
        $this->assertSame(route('manufacturers.show', ['slug' => 'guess-satovi'], false), $links->item(0)->getAttribute('href'));
        $cards = $xpath->query('.//*[@data-product-card]', $sections->item(0));
        $ids = array_map(fn ($card) => (int) $card->getAttribute('data-product-id'), iterator_to_array($cards));
        sort($ids);
        $this->assertSame([$first->id, $second->id], $ids);
    }

    private function manufacturer(string $code, string $name, string $slug): Manufacturer
    {
        $manufacturer = Manufacturer::create(['code' => $code, 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => $slug]);

        return $manufacturer;
    }

    private function category(string $slug, string $name, ?Category $parent = null): Category
    {
        $category = Category::create(['code' => $slug, 'scope' => 'catalog', 'is_active' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => $name, 'slug' => $slug]);

        return $category;
    }

    private function product(string $slug, array $attributes = []): Product
    {
        $product = Product::create(array_replace([
            'code' => $slug, 'sku' => strtoupper($slug), 'manufacturer_id' => $this->guess->id,
            'is_active' => true, 'stock_qty' => 3, 'base_price' => 99,
            'payload' => ['opencart' => ['image' => 'catalog/'.$slug.'.jpg', 'date_added' => '2026-10-15 10:00:00']],
        ], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sat '.$slug, 'slug' => $slug]);
        $product->categories()->attach($this->watches);

        return $product;
    }
}
