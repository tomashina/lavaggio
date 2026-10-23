<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchlineFrontendAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        config([
            'storefront.home' => 'watchline', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'storefront_cache.enabled' => false, 'legacy_media.enabled' => true,
            'legacy_media.origin' => 'https://www.watchline.hr', 'commerce.b2b_only' => false,
            'stock_notifications.enabled' => true,
        ]);
        app(SystemSettingsService::class)->put('store_brand_name', 'WATCH LINE AM - Web Shop');
    }

    public function test_page_styles_are_discovered_directly_in_cascade_order_without_admin_javascript(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query('//head/link[@rel="preload" and @as="font" and contains(@href, "Sora-Variable.woff2")]')->count());
        $links = $xpath->query('//head/link[@rel="stylesheet"]');
        $hrefs = array_map(fn ($link) => $link->getAttribute('href'), iterator_to_array($links));
        $pages = array_find_key($hrefs, fn ($href) => str_contains($href, '/watchline-pages.css?'));
        $commerce = array_find_key($hrefs, fn ($href) => str_contains($href, '/watchline-commerce.css?'));
        $identity = array_find_key($hrefs, fn ($href) => str_contains($href, '/watchline.css?'));
        $this->assertNotNull($pages);
        $this->assertNotNull($commerce);
        $this->assertNotNull($identity);
        $this->assertLessThan($commerce, $pages);
        $this->assertLessThan($identity, $commerce);
        $this->assertSame(0, $xpath->query('//script[@type="module" and contains(@src, "/build/assets/app-")]')->count());
        $this->assertStringNotContainsString('stock-notifications.js', $html);
        $this->assertStringNotContainsString('stock-notifications.css', $html);
    }

    public function test_watchline_hover_photos_have_no_initial_network_source_but_primary_images_keep_their_priority(): void
    {
        $category = Category::create(['code' => 'asset-watches', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'name' => 'Satovi', 'slug' => 'asset-watches']);
        foreach (range(1, 2) as $index) {
            $product = $this->product('asset-watch-'.$index);
            $product->categories()->attach($category);
        }

        $xpath = $this->xpath($this->get(route('categories.show', ['slug' => 'asset-watches']))->assertOk()->getContent());
        $primary = $xpath->query('//*[@data-catalog-grid]//img[@data-product-card-image]');
        $hover = $xpath->query('//*[@data-catalog-grid]//img[@data-product-card-hover-image]');
        $this->assertSame(2, $primary->count());
        $this->assertSame(2, $hover->count());
        $this->assertSame('eager', $primary->item(0)->getAttribute('loading'));
        $this->assertSame('high', $primary->item(0)->getAttribute('fetchpriority'));
        foreach ($hover as $image) {
            $this->assertFalse($image->hasAttribute('src'));
            $this->assertFalse($image->hasAttribute('srcset'));
            $this->assertStringContainsString('/image/catalog/', $image->getAttribute('data-hover-src'));
            $this->assertGreaterThan(0, (int) $image->getAttribute('width'));
            $this->assertGreaterThan(0, (int) $image->getAttribute('height'));
        }
    }

    public function test_real_stock_alert_actions_keep_one_asset_pair_and_their_html_form_fallback(): void
    {
        $product = $this->product('unavailable-watch', 0);
        foreach ([route('products.show', ['slug' => 'unavailable-watch']), route('stock-notifications.create', ['product_id' => $product->id])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $xpath = $this->xpath($html);
            $this->assertSame(1, $xpath->query('//head/link[contains(@href, "/stock-notifications.css?")]')->count());
            $this->assertSame(1, $xpath->query('//script[contains(@src, "/stock-notifications.js?")]')->count());
            $this->assertStringContainsString('data-stock-notify-form', $html);
        }
    }

    public function test_first_visible_campaign_owns_one_h1_even_when_an_editor_adds_another_campaign(): void
    {
        foreach (['Prvi CLUSE naslov', 'Drugi CLUSE naslov'] as $index => $title) {
            $block = ContentBlock::create(['code' => 'asset-campaign-'.$index, 'name' => $title, 'type' => 'watchline_campaigns', 'is_active' => true]);
            $block->translations()->create(['locale' => 'hr', 'title' => $title, 'payload' => ['watchline' => ['cluse_title' => $title]]]);
            $block->slots()->create(['placement' => 'home.content', 'frontend_variant' => 'all', 'sort_order' => $index, 'is_active' => true]);
        }

        $xpath = $this->xpath($this->get('/')->assertOk()->getContent());
        $this->assertSame(1, $xpath->query('//main//h1')->count());
        $heading = $xpath->query('//main//*[@data-watchline-cluse-campaign]//h1')->item(0);
        $this->assertSame('Prvi CLUSE naslov', trim($heading->textContent));
        $this->assertSame('wl-campaign-title', $heading->getAttribute('class'));
        $this->assertSame('Drugi CLUSE naslov', trim($xpath->query('//main//*[@data-watchline-cluse-campaign]//h2')->item(0)->textContent));
    }

    private function product(string $slug, int $stock = 3): Product
    {
        $product = Product::create([
            'code' => $slug, 'is_active' => true, 'base_price' => 25, 'stock_qty' => $stock,
            'payload' => ['opencart' => ['image' => 'catalog/products/'.$slug.'.jpg', 'gallery_images' => [['image' => 'catalog/products/'.$slug.'-back.jpg']]]],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Watch '.$slug, 'slug' => $slug]);

        return $product;
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($html);

        return new DOMXPath($dom);
    }
}
