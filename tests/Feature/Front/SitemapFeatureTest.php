<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_contains_only_active_canonical_catalog_urls_and_no_prices(): void
    {
        config(['app.url' => 'https://www.herrera.hr', 'app.locale' => 'hr', 'commerce.b2b_only' => true]);
        foreach ([['active', true], ['inactive', false]] as [$code, $active]) {
            $product = Product::query()->create(['code' => $code, 'sku' => $code, 'is_active' => $active, 'base_price' => 137.49]);
            $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        }
        $index = $this->get('/sitemap.xml')->assertOk()->assertSee('https://www.herrera.hr/sitemap-products-1.xml', false);
        $this->assertNotFalse(simplexml_load_string($index->getContent()));
        $part = $this->get('/sitemap-products-1.xml')->assertOk()
            ->assertSee('https://www.herrera.hr/product/active', false)->assertDontSee('/product/inactive', false)->assertDontSee('137.49');
        $this->assertNotFalse(simplexml_load_string($part->getContent()));
        $this->get('/sitemap-products-99.xml')->assertNotFound();
    }

    public function test_robots_blocks_preview_and_uses_own_domain_in_production(): void
    {
        $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /\n")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://www.herrera.hr']);
        $this->get('https://www.herrera.hr/robots.txt')->assertOk()
            ->assertSee('Sitemap: https://www.herrera.hr/sitemap.xml', false)
            ->assertDontSee("Disallow: /\n", false)->assertDontSee('videonadzor')
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_known_test_hosts_remain_blocked_even_with_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://www.herrera.hr']);

        foreach (['http://herrera-new.test', 'http://watchline-new.test', 'http://localhost', 'http://127.0.0.1', 'http://[::1]', 'https://herrera.herrera.hr'] as $origin) {
            $this->get($origin.'/robots.txt')->assertOk()
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertContent("User-agent: *\nDisallow: /\n");
            $this->get($origin.'/contact')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->get($origin.'/sitemap.xml')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $this->get('https://www.herrera.hr/contact')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_staging_environment_blocks_indexing_regardless_of_requested_host(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config(['app.url' => 'https://herrera.herrera.hr']);
        $this->get('https://www.herrera.hr/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /\n");
        $this->get('https://www.herrera.hr/contact')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_blog_sitemap_only_lists_enabled_published_posts(): void
    {
        config(['app.url' => 'https://www.herrera.hr', 'app.locale' => 'hr']);
        app(SystemSettingsService::class)->put('catalog_use_blog', true);
        foreach ([['published', true, now()->subDay()], ['disabled', false, null], ['future', true, now()->addDay()]] as [$code, $active, $published]) {
            $post = BlogPost::query()->create(['code' => $code, 'is_active' => $active, 'published_at' => $published]);
            $post->translations()->create(['locale' => 'hr', 'title' => $code, 'slug' => $code]);
        }
        $this->get('/sitemap.xml')->assertOk()->assertSee('/sitemap-blog-1.xml', false);
        $this->get('/sitemap-blog-1.xml')->assertOk()->assertSee('https://www.herrera.hr/blog/published', false)
            ->assertDontSee('/blog/disabled', false)->assertDontSee('/blog/future', false);
        app(SystemSettingsService::class)->put('catalog_use_blog', false);
        $this->get('/sitemap-blog-1.xml')->assertOk()->assertDontSee('/blog/published', false);
    }

    public function test_blog_sitemap_does_not_include_a_translated_slug_whose_article_page_is_not_visible(): void
    {
        config(['app.url' => 'https://www.watchline.hr', 'app.locale' => 'hr', 'storefront.home' => 'watchline']);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('catalog_use_blog', true);
        $post = BlogPost::query()->create(['code' => 'empty-title', 'is_active' => true]);
        $post->translations()->create(['locale' => 'hr', 'title' => '   ', 'slug' => 'empty-title']);

        $this->get('/blog/empty-title')->assertNotFound();
        $this->get('/sitemap-blog-1.xml')->assertOk()->assertDontSee('/blog/empty-title', false);
    }

    public function test_watchline_sitemap_lists_public_entry_pages_and_respects_disabled_features(): void
    {
        config(['app.url' => 'https://www.watchline.hr', 'app.locale' => 'hr', 'storefront.home' => 'watchline', 'gift_vouchers.enabled' => true]);
        $settings = app(SystemSettingsService::class);
        $settings->put('catalog_use_blog', true);
        $settings->put('catalog_use_manufacturers', true);
        $brand = Manufacturer::query()->create(['code' => 'test-brand', 'is_active' => true]);
        $brand->translations()->create(['locale' => 'hr', 'name' => 'Test brand', 'slug' => 'test-brand']);

        $response = $this->get('/sitemap-pages-1.xml')->assertOk();
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $locations = array_map('strval', $xml->xpath('//*[local-name()="loc"]'));
        foreach (['/', '/shop', '/categories', '/contact', '/brendovi', '/blog', '/poklon-bon', '/savjeti-za-poklone', '/iskustva-kupaca'] as $path) {
            $this->assertContains('https://www.watchline.hr'.$path, $locations);
        }
        $response->assertDontSee('/cart', false)->assertDontSee('/checkout', false)->assertDontSee('?category=', false);
        $this->get('/sitemap-manufacturers-1.xml')->assertOk()->assertSee('/manufacturer/test-brand', false);

        $settings->put('catalog_use_blog', false);
        $settings->put('catalog_use_manufacturers', false);
        config(['gift_vouchers.enabled' => false]);
        $this->get('/sitemap-pages-1.xml')->assertOk()->assertDontSee('<loc>https://www.watchline.hr/blog</loc>', false)
            ->assertDontSee('<loc>https://www.watchline.hr/brendovi</loc>', false)
            ->assertDontSee('<loc>https://www.watchline.hr/poklon-bon</loc>', false);
        $this->get('/sitemap-manufacturers-1.xml')->assertOk()->assertDontSee('/manufacturer/test-brand', false);
    }

    public function test_sitemap_preserves_hidden_seo_categories_and_uses_real_translation_modification_date(): void
    {
        config(['app.url' => 'https://www.watchline.hr', 'app.locale' => 'hr']);
        $old = now()->subDays(10)->startOfSecond();
        $new = now()->subDay()->startOfSecond();
        $category = Category::query()->create(['code' => 'hidden-seo-category', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true, 'show_in_menu' => false, 'updated_at' => $old]);
        $category->translations()->create(['locale' => 'hr', 'name' => 'Ženski satovi Cluse', 'slug' => 'zenski-satovi-cluse', 'updated_at' => $new]);
        // Translation timestamps are not mass assignable, just as in real edited rows.
        $category->translations()->update(['updated_at' => $new]);
        $category->update(['ends_at' => null]);
        Category::query()->whereKey($category->id)->update(['updated_at' => $old]);

        $response = $this->get('/sitemap-categories-1.xml')->assertOk()
            ->assertSee('/category/zenski-satovi-cluse', false)->assertSee('<lastmod>'.$new->toAtomString().'</lastmod>', false);
        $this->assertNotFalse(simplexml_load_string($response->getContent()));

        $category->update(['ends_at' => now()->subHour()]);
        $this->get('/sitemap-categories-1.xml')->assertOk()->assertDontSee('/category/zenski-satovi-cluse', false);
    }

    public function test_production_robots_blocks_private_routes_but_allows_facets_to_be_read_for_noindex(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://www.watchline.hr']);
        $response = $this->get('https://www.watchline.hr/robots.txt')->assertOk();
        foreach (['/auth/', '/wishlist', '/checkout', '/poklon-bon/narudzba/', '/obavijesti-o-dostupnosti/odjava/'] as $path) {
            $response->assertSee('Disallow: '.$path, false);
        }
        $response->assertDontSee('Disallow: /*?', false)->assertDontSee('Disallow: /manufacturer', false)
            ->assertSee('Sitemap: https://www.watchline.hr/sitemap.xml', false);
    }
}
