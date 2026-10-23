<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use App\Support\WatchlineArticleImages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchlineArticleImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        app()->setLocale('hr');
        app(\App\Services\Settings\SystemSettingsService::class)->put('catalog_use_blog', true);
    }

    public function test_explicit_captions_titles_and_existing_alts_are_preserved_without_rewriting_article_text(): void
    {
        $html = '<p class="old-word">Tekst &amp; čuvamo sadržaj.</p><figure><img src="/missing.jpg"><figcaption>Seiko s plavim brojčanikom</figcaption></figure>'
            .'<img src="/second.jpg" title="CLUSE sat"><img src="/third.jpg" alt="Autorski opis"><img src="/decorative.jpg" alt="">';
        $result = app(WatchlineArticleImages::class)->render($html, true);
        $this->assertStringContainsString('<p class="old-word">Tekst &amp; čuvamo sadržaj.</p>', $result);
        $this->assertStringContainsString('alt="Seiko s plavim brojčanikom"', $result);
        $this->assertStringContainsString('alt="CLUSE sat"', $result);
        $this->assertStringContainsString('alt="Autorski opis"', $result);
        $this->assertStringContainsString('alt=""', $result);
        $this->assertSame(4, substr_count($result, 'loading="lazy"'));
        $this->assertSame(4, substr_count($result, 'decoding="async"'));
        $this->assertStringNotContainsString('width=', $result);
    }

    public function test_linked_product_captions_resolve_native_and_legacy_names_with_bounded_queries(): void
    {
        $product = Product::create(['code' => 'article-watch', 'sku' => 'SK123', 'is_active' => true, 'base_price' => 20]);
        $product->translations()->create(['locale' => 'hr', 'slug' => 'article-watch', 'name' => 'SEIKO SK123']);
        DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => 'product', 'source_id' => '123', 'checksum' => str_repeat('a', 64), 'target_id' => $product->id]);
        DB::table('herrera_legacy_urls')->insert(['locale' => 'hr', 'path' => '/old-watch', 'path_hash' => hash('sha256', '/old-watch'), 'source_query' => 'product_id=123', 'status' => 'redirect', 'destination' => '/product/article-watch']);
        $html = '<p><img src="/image/a.jpg"></p><p>Kupite ovdje: <a href="/product/article-watch">OVDJE</a></p>'
            .'<p>Novi odjeljak</p><p><img src="/image/b.jpg"></p><p>Kupite ovdje:https://www.watchline.hr/seiko/old-watch</p>';
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(WatchlineArticleImages::class)->render($html, true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(2, substr_count($result, 'alt="SEIKO SK123"'));
        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertSame($html, str_replace([' alt="SEIKO SK123" loading="lazy" decoding="async"'], '', $result));
    }

    public function test_uncaptioned_content_and_ambiguous_multi_image_figures_are_not_given_imagined_descriptions(): void
    {
        $html = '<p>Naslov članka o novim satovima.</p><p><img src="https://www.watchline.hr/image/unknown.png"></p>'
            .'<p>Ovdje je običan tekst, a ne opis slike.</p><figure><img src="/one.jpg"><img src="/two.jpg"><figcaption>Različiti modeli</figcaption></figure>';
        $result = app(WatchlineArticleImages::class)->render($html);
        $this->assertStringNotContainsString('alt=', $result);
        $this->assertStringNotContainsString('width=', $result);
        $this->assertSame(2, substr_count($result, 'loading="lazy"'));
        $this->assertSame(3, substr_count($result, 'decoding="async"'));
        $this->assertStringContainsString('src="https://www.watchline.hr/image/unknown.png" decoding="async"', $result);
    }

    public function test_purchase_caption_can_supply_its_own_label_without_any_product_or_remote_lookup(): void
    {
        $html = '<p><img src="/s.jpg"></p><p>Seiko 5 Sports GMT s crnim brojčanikom, kupite ovdje: https://www.watchline.hr/unknown-model</p>';
        $result = app(WatchlineArticleImages::class)->render($html, true);
        $this->assertStringContainsString('alt="Seiko 5 Sports GMT s crnim brojčanikom"', $result);
        $this->assertStringContainsString('https://www.watchline.hr/unknown-model</p>', $result);
    }

    public function test_blog_and_info_page_render_the_helper_but_other_brands_keep_original_html(): void
    {
        $post = BlogPost::create(['code' => 'image-test', 'is_active' => true]);
        $post->translations()->create(['locale' => 'hr', 'slug' => 'image-test', 'title' => 'Članak', 'body_html' => '<p><img src="/not-local.jpg" title="Objavljeni opis"></p>']);
        $page = InfoPage::create(['code' => 'image-page', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'slug' => 'image-page', 'title' => 'Stranica', 'body_html' => '<figure><img src="/page.jpg"><figcaption>Izvorni natpis</figcaption></figure>']);
        $this->get('/blog/image-test')->assertOk()->assertSee('alt="Objavljeni opis"', false);
        $this->get('/page/image-page')->assertOk()->assertSee('alt="Izvorni natpis"', false);
        $this->assertSame('<p><img src="/not-local.jpg" title="Objavljeni opis"></p>', $post->translations()->first()->body_html);
        config(['storefront.home' => 'auto']);
        $original = '<img src="/still-unmodified.jpg">';
        $this->assertSame($original, app(WatchlineArticleImages::class)->render($original));
    }
}
