<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Support\Comment;
use App\Models\Content\Support\Faq;
use App\Models\User;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use App\Support\WatchlineSeo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WatchlineSeoMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'legacy_media.enabled' => true,
            'legacy_media.origin' => 'https://www.watchline.hr', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Watchline', 'store_seo_default_description' => 'Ponuda Watchline.',
            'store_seo_robots' => 'index,follow', 'store_schema_business_name' => 'Watchline']);
    }

    public function test_clean_pagination_has_its_own_canonical_while_tracking_and_first_page_are_normalized(): void
    {
        $this->app['env'] = 'production';
        $pagination = new LengthAwarePaginator([], 150, 60, 2);
        $html = $this->meta('categories.show', '/category/satovi?page=2&utm_source=test', ['products' => $pagination]);
        $this->assertStringContainsString('<link rel="canonical" href="https://www.watchline.hr/category/satovi?page=2">', $html);
        $this->assertStringContainsString('<meta name="robots" content="index,follow">', $html);
        $this->assertStringContainsString('Stranica 2', $html);
        $this->assertStringNotContainsString('utm_source', $html);
        $first = $this->meta('categories.show', '/category/satovi?page=1&utm_source=test', ['products' => new LengthAwarePaginator([], 150, 60, 1)]);
        $this->assertStringContainsString('rel="canonical" href="https://www.watchline.hr/category/satovi"', $first);
    }

    public function test_ui_facets_search_and_private_pages_are_noindex_without_blocking_clean_catalog_or_global_policy(): void
    {
        $this->app['env'] = 'production';
        foreach ([['categories.show', '/category/satovi?attr_watchline_collection=7'], ['shop.index', '/shop?q=SEIKO'],
            ['manufacturers.show', '/manufacturer/guess?category=muski-satovi'], ['gift-guide.index', '/savjeti-za-poklone?recipient=for_her'],
            ['categories.show', '/category/satovi?sort=price_low'], ['categories.show', '/category/satovi?cols=4'], ['cart.index', '/cart']] as [$name, $url]) {
            $html = $this->meta($name, $url);
            $this->assertStringContainsString('<meta name="robots" content="noindex,follow">', $html, $url);
            $this->assertStringContainsString('rel="canonical" href="https://www.watchline.hr'.strtok($url, '?').'"', $html);
        }
        $request = $this->requestFor('categories.show', '/category/deep-third-level?utm_campaign=test');
        $this->assertSame('index,follow', WatchlineSeo::robots($request, 'index,follow'));
        $this->assertSame('noindex,nofollow', WatchlineSeo::robots($request, 'noindex,nofollow'));
        $this->app['env'] = 'local';
        $this->assertSame('noindex,nofollow', WatchlineSeo::robots($request, 'index,follow'));
    }

    public function test_manufacturer_and_gift_guide_use_their_own_visible_metadata_and_collection_identity(): void
    {
        $brand = Manufacturer::create(['code' => 'guess', 'is_active' => true]);
        $brand->translations()->create(['locale' => 'hr', 'slug' => 'guess-satovi', 'name' => 'GUESS',
            'meta_title' => 'GUESS satovi', 'description' => '<p>GUESS satovi &amp; odabrani modeli.</p>']);
        $html = $this->meta('manufacturers.show', '/manufacturer/guess-satovi', ['manufacturer' => $brand]);
        $this->assertStringContainsString('<title>GUESS satovi</title>', $html);
        $this->assertStringContainsString('content="GUESS satovi &amp; odabrani modeli."', $html);
        $schemas = $this->schemas('manufacturers.show', '/manufacturer/guess-satovi', ['manufacturer' => $brand]);
        $this->assertSame('GUESS satovi', $schemas->firstWhere('@type', 'CollectionPage')['name']);
        $this->assertSame('GUESS', collect($schemas->firstWhere('@type', 'BreadcrumbList')['itemListElement'])->last()['name']);
        $gift = $this->meta('gift-guide.index', '/savjeti-za-poklone');
        $this->assertStringContainsString('<title>Tražiš poklon? – Watchline</title>', $gift);
        $this->assertStringContainsString('Pronađite sat, sunčane naočale ili nakit', $gift);
        $this->assertSame('CollectionPage', $this->schemas('gift-guide.index', '/savjeti-za-poklone')->firstWhere('@type', 'CollectionPage')['@type']);
    }

    public function test_product_offer_reuses_actual_promotion_and_stock_and_only_real_identifiers_and_visible_ratings(): void
    {
        $product = $this->product();
        $product->update(['barcode' => '4006381333931', 'stock_qty' => 0, 'supplier_stock_qty' => 3]);
        $approved = $product->comments()->create(['locale' => 'hr', 'author_name' => 'Kupac', 'author_email' => 'not-public@example.test',
            'body' => 'Odličan sat.', 'rating' => 4, 'status' => Comment::STATUS_APPROVED]);
        $product->comments()->create(['locale' => 'hr', 'author_name' => 'Odgovor', 'body' => 'Odgovor', 'rating' => 5,
            'status' => Comment::STATUS_APPROVED, 'parent_id' => $approved->id]);
        $product->comments()->create(['locale' => 'de', 'author_name' => 'Drugi jezik', 'body' => 'Drugi jezik', 'rating' => 1, 'status' => Comment::STATUS_APPROVED]);
        $product->comments()->create(['locale' => 'hr', 'author_name' => 'Nevažeće', 'body' => 'Nevažeće', 'rating' => 9, 'status' => Comment::STATUS_APPROVED]);
        $schema = $this->schemas('products.show', '/product/fixture-watch', ['product' => $product, 'comments' => collect([$approved]),
            'reviewDistribution' => [4 => 1], 'pricePresentation' => ['display_current' => 85, 'current_gross' => 85, 'display_includes_tax' => true]])->firstWhere('@type', 'Product');
        $this->assertSame('85.00', $schema['offers']['price']);
        $this->assertSame('EUR', $schema['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        $this->assertSame('4006381333931', $schema['gtin13']);
        $this->assertArrayNotHasKey('mpn', $schema);
        $this->assertSame(1, $schema['aggregateRating']['reviewCount']);
        $this->assertSame('4.00', $schema['aggregateRating']['ratingValue']);
        $this->assertSame('Odličan sat.', $schema['review'][0]['reviewBody']);
        $this->assertStringNotContainsString('not-public@example.test', json_encode($schema));
        $this->assertSame([], WatchlineSeo::identifiers(new Product(['barcode' => '4006381333932'])));
        $this->assertSame([], WatchlineSeo::identifiers(new Product(['barcode' => 'SKU-001'])));
        $this->assertSame([], WatchlineSeo::identifiers(new Product(['barcode' => '0000000000000'])));
    }

    public function test_json_ld_cannot_close_its_script_and_a_store_logo_is_never_used_as_a_product_photo(): void
    {
        $product = $this->product();
        $product->update(['sku' => '</script><script>window.bad=1</script>', 'payload' => []]);
        config(['commerce.b2c_only' => false, 'commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_og_default_image_path', 'assets/brand/watchline-logo.svg');
        $this->requestFor('products.show', '/product/fixture-watch');
        $settings = app(StoreSettingsService::class)->all();
        $settings['og']['default_image_url'] = '/assets/brand/watchline-logo.svg';
        $html = view('front.partials.schema-markup', ['storeSettings' => $settings, 'product' => $product,
            'locale' => 'hr', 'fallbackLocale' => 'hr', 'canViewPrices' => false])->render();
        $this->assertStringNotContainsString('<script>window.bad', $html);
        preg_match_all('~<script type="application/ld\\+json">(.*?)</script>~s', $html, $matches);
        $schema = collect($matches[1])->map(fn ($json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR))->firstWhere('@type', 'Product');
        $this->assertSame($product->sku, $schema['sku']);
        $this->assertArrayNotHasKey('image', $schema);
        $this->assertArrayNotHasKey('offers', $schema);
    }

    public function test_hidden_home_faq_is_not_claimed_and_blog_business_author_is_an_organization(): void
    {
        $faq = Faq::create(['code' => 'fixture', 'is_active' => true]);
        $faq->translations()->create(['locale' => 'hr', 'slug' => 'fixture', 'question' => 'Pitanje?', 'answer_html' => '<p>Odgovor.</p>']);
        $this->assertNull($this->schemas('home', '/')->firstWhere('@type', 'FAQPage'));
        $this->assertNotNull($this->schemas('faq.index', '/faq')->firstWhere('@type', 'FAQPage'));
        $admin = User::factory()->create(['name' => 'Private Admin']);
        $post = BlogPost::create(['code' => 'fixture-blog', 'is_active' => true, 'created_by' => $admin->id, 'published_at' => now()->subDay()]);
        $post->translations()->create(['locale' => 'hr', 'slug' => 'fixture-blog', 'title' => 'Kako odabrati sat', 'body_html' => '<p>Usporedite modele &amp; odaberite svoj sat.</p>']);
        $schema = $this->schemas('blog.show', '/blog/fixture-blog', ['post' => $post])->firstWhere('@type', 'BlogPosting');
        $this->assertSame(['@type' => 'Organization', 'name' => 'Watchline'], $schema['author']);
        $this->assertStringNotContainsString('Private Admin', json_encode($schema));
        $this->assertSame('Usporedite modele & odaberite svoj sat.', $schema['description']);
    }

    public function test_real_product_http_response_keeps_visible_sale_price_and_json_ld_in_agreement(): void
    {
        $category = Category::create(['code' => 'watches', 'scope' => 'catalog', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Satovi', 'slug' => 'satovi']);
        $product = $this->product();
        $product->categories()->attach($category);
        CatalogAction::create(['code' => 'seo-sale', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 15,
            'target_type' => 'all', 'audience_type' => 'all', 'is_active' => true]);
        $response = $this->get('/product/fixture-watch?utm_source=test')->assertOk();
        $html = $response->getContent();
        preg_match('~<link rel="canonical" href="([^"]+)"~', $html, $canonical);
        $this->assertSame('/product/fixture-watch', parse_url($canonical[1], PHP_URL_PATH));
        $this->assertNull(parse_url($canonical[1], PHP_URL_QUERY));
        preg_match_all('~<script type="application/ld\\+json">(.*?)</script>~s', $html, $matches);
        $schema = collect($matches[1])->map(fn ($json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR))->firstWhere('@type', 'Product');
        $this->assertSame('85.00', $schema['offers']['price']);
        $response->assertViewHas('pricePresentation', fn ($price) => $price['display_current'] === 85.0);
        $this->assertStringNotContainsString('utm_source', $schema['url']);
    }

    public function test_blog_topic_pagination_and_blog_post_entities_keep_the_actual_editorial_identity(): void
    {
        $post = BlogPost::create(['code' => 'topic-post', 'is_active' => true]);
        $post->translations()->create(['locale' => 'hr', 'slug' => 'topic-post', 'title' => 'Novosti iz ponude']);
        $posts = new LengthAwarePaginator([$post], 30, 10, 2);
        $html = $this->meta('blog.index', '/blog?category=novosti&page=2&utm_source=test', ['posts' => $posts]);
        $this->assertStringContainsString('rel="canonical" href="https://www.watchline.hr/blog?category=novosti&amp;page=2"', $html);
        $schema = $this->schemas('blog.index', '/blog?category=novosti&page=2', ['posts' => $posts])->firstWhere('@type', 'Blog');
        $this->assertSame('BlogPosting', $schema['blogPost'][0]['@type']);
        $this->assertSame('Novosti iz ponude', $schema['blogPost'][0]['headline']);
        $this->assertArrayNotHasKey('position', $schema['blogPost'][0]);
    }

    public function test_static_public_pages_have_specific_watchline_metadata_without_inherited_toy_brand_copy(): void
    {
        foreach (['shop.index', 'categories.index', 'manufacturers.index', 'blog.index', 'contact.create', 'reviews.index', 'faq.index'] as $name) {
            $html = $this->meta($name, '/seo-fixture');
            $this->assertStringContainsString('Watchline</title>', $html, $name);
            $this->assertStringNotContainsString('Liber Media', $html);
            $this->assertStringNotContainsString('BALI', $html);
            $this->assertStringNotContainsString('content="Ponuda Watchline."', $html);
        }
    }

    public function test_reviews_and_page_paginators_have_distinct_second_page_canonicals_and_title_suffixes(): void
    {
        foreach (['reviews', 'pages'] as $variable) {
            $html = $this->meta('reviews.index', '/iskustva-kupaca?page=2', [$variable => new LengthAwarePaginator([], 70, 30, 2)]);
            $this->assertStringContainsString('rel="canonical" href="https://www.watchline.hr/iskustva-kupaca?page=2"', $html);
            $this->assertStringContainsString('<title>Iskustva kupaca – Watchline – Stranica 2</title>', $html);
        }
    }

    public function test_long_multibyte_description_ends_at_a_whole_word_without_modifying_source_text(): void
    {
        $product = $this->product();
        $prefix = str_repeat('čisto ', 52);
        $description = $prefix.'nehrđajućeg čelika.';
        $product->translations()->first()->update(['meta_description' => $description]);
        $product->load('translations');
        $html = $this->meta('products.show', '/product/fixture-watch', ['product' => $product]);
        $this->assertStringContainsString('<meta name="description" content="'.rtrim($prefix).'">', $html);
        $this->assertStringNotContainsString('nehrđaj', $html);
        $this->assertSame($description, $product->translations()->first()->meta_description);
    }

    public function test_other_brands_keep_their_metadata_policy(): void
    {
        config(['storefront.home' => 'auto']);
        $this->app['env'] = 'production';
        $html = $this->meta('categories.show', '/category/satovi?page=2&cols=4', ['products' => new LengthAwarePaginator([], 150, 60, 2)]);
        $this->assertStringContainsString('rel="canonical" href="https://www.watchline.hr/category/satovi"', $html);
        $this->assertStringContainsString('content="index,follow"', $html);
    }

    public function test_disabled_schema_stays_disabled(): void
    {
        app(SystemSettingsService::class)->put('store_schema_enabled', false);
        $this->assertCount(0, $this->schemas('products.show', '/product/fixture-watch', ['product' => $this->product()]));
    }

    private function meta(string $name, string $url, array $data = []): string
    {
        $this->requestFor($name, $url);

        return view('front.partials.seo-meta', $data + ['storeSettings' => app(StoreSettingsService::class)->all(), 'locale' => 'hr', 'fallbackLocale' => 'hr'])->render();
    }

    private function schemas(string $name, string $url, array $data = [])
    {
        $this->requestFor($name, $url);
        $html = view('front.partials.schema-markup', $data + ['storeSettings' => app(StoreSettingsService::class)->all(),
            'locale' => 'hr', 'fallbackLocale' => 'hr', 'canViewPrices' => true])->render();
        preg_match_all('~<script type="application/ld\\+json">(.*?)</script>~s', $html, $matches);

        return collect($matches[1])->map(fn ($json) => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    private function requestFor(string $name, string $url): Request
    {
        $request = Request::create('https://www.watchline.hr'.$url);
        $route = new Route('GET', strtok($url, '?'), []);
        $route->name($name);
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        URL::setRequest($request);

        return $request;
    }

    private function product(): Product
    {
        $product = Product::create(['code' => 'seo-watch', 'sku' => 'SEO-WATCH', 'base_price' => 100, 'stock_qty' => 5, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Fixture Watch', 'slug' => 'fixture-watch', 'description' => 'Opis sata.']);

        return $product;
    }
}
