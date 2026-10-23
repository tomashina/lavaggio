<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Front\GuestStorefrontCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalPreviewCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_catalog_and_product_pages_ignore_old_document_validators(): void
    {
        [$category, $product] = $this->catalog();
        $this->app['env'] = 'production';
        foreach ([route('categories.show', ['slug' => 'preview-watches']), route('products.show', ['slug' => 'preview-watch'])] as $url) {
            $original = $this->get($url)->assertOk();
            $etag = $original->headers->get('ETag');
            $this->assertNotEmpty($etag);

            // Reproduce the old document's conditional request without changing catalog data.
            $this->app['env'] = 'local';
            $fresh = $this->withHeaders([
                'If-None-Match' => $etag,
                'If-Modified-Since' => $original->headers->get('Last-Modified'),
            ])->get($url)->assertOk();
            $this->assertStringContainsString('no-store', $fresh->headers->get('Cache-Control'));
            $this->assertStringContainsString('max-age=0', $fresh->headers->get('Cache-Control'));
            $this->assertNull($fresh->headers->get('ETag'));
            $this->assertNull($fresh->headers->get('Last-Modified'));
            $this->assertStringContainsString('<!DOCTYPE html>', $fresh->getContent());
            $this->app['env'] = 'production';
            $this->flushHeaders();
        }
    }

    public function test_production_keeps_conditional_catalog_responses_and_configurable_guest_cache(): void
    {
        $this->catalog();
        config(['commerce.b2c_only' => true, 'storefront_cache.enabled' => true, 'storefront_cache.public_b2c_enabled' => true]);
        $this->app['env'] = 'local';
        $this->assertFalse(app(GuestStorefrontCache::class)->enabled());

        $this->app['env'] = 'production';
        $this->assertTrue(app(GuestStorefrontCache::class)->enabled());
        // Disable internal HTML storage to exercise the existing browser validators directly.
        config(['storefront_cache.enabled' => false]);
        $url = route('categories.show', ['slug' => 'preview-watches']);
        $original = $this->get($url)->assertOk();
        $this->assertStringNotContainsString('no-store', $original->headers->get('Cache-Control'));
        $this->withHeaders(['If-None-Match' => $original->headers->get('ETag')])->get($url)->assertStatus(304);
    }

    private function catalog(): array
    {
        config(['commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false]);
        $category = Category::create(['code' => 'preview-watches', 'scope' => 'catalog', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Satovi', 'slug' => 'preview-watches']);
        $product = Product::create(['code' => 'preview-watch', 'sku' => 'PREVIEW-WATCH', 'base_price' => 100, 'stock_qty' => 1, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Preview watch', 'slug' => 'preview-watch']);
        $product->categories()->attach($category);

        return [$category, $product];
    }
}
