<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Blog\BlogPost;
use App\Services\Import\LibermediaBlogCategoryService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlogDetailDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia', 'storefront.home' => 'libermedia', 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Liber Media', 'catalog_use_blog' => true]);
    }

    public function test_blog_lists_thirty_articles_per_page(): void
    {
        for ($index = 1; $index <= 31; $index++) {
            $this->article('pagination-'.$index);
        }

        $response = $this->get(route('blog.index'))->assertOk();
        $this->assertSame(30, $response->viewData('posts')->perPage());
        $this->assertCount(30, $response->viewData('posts'));
        $this->assertCount(1, $this->get(route('blog.index', ['page' => 2]))->assertOk()->viewData('posts'));
    }

    public function test_related_articles_share_a_visible_blog_category_and_are_public_with_usable_translations(): void
    {
        $current = $this->article('roman-empire');
        $history = $this->category('history');
        $current->categories()->attach($history);
        $related = $this->article('ancient-history');
        $related->categories()->attach($history);
        $unrelated = $this->article('play-ideas');
        $inactive = $this->article('inactive-history', ['is_active' => false]);
        $future = $this->article('future-history', ['published_at' => now()->addDay()]);
        $untranslated = $this->article('italian-history', [], 'it');
        $noSlug = $this->article('no-slug');
        $noSlug->translations()->update(['slug' => ' ']);
        $noTitle = $this->article('no-title');
        $noTitle->translations()->update(['title' => ' ']);
        foreach ([$inactive, $future, $untranslated, $noSlug, $noTitle] as $hidden) {
            $hidden->categories()->attach($history);
        }

        $this->get(route('blog.show', ['slug' => $current->code]))->assertOk()
            ->assertViewHas('related', fn ($posts): bool => $posts->modelKeys() === [$related->id])
            ->assertSee('data-blog-related-articles', false)->assertSee('Vezani članci')
            ->assertSee('href="'.route('blog.show', ['slug' => $related->code]).'"', false)
            ->assertDontSee($unrelated->translations->first()->title);
    }

    public function test_hidden_scheduled_or_catalog_categories_do_not_create_article_relationships(): void
    {
        $current = $this->article('category-visibility');
        $candidate = $this->article('only-hidden-categories');
        foreach ([['is_active' => false], ['starts_at' => now()->addDay()], ['ends_at' => now()->subDay()], ['scope' => Category::SCOPE_CATALOG]] as $index => $fields) {
            $category = $this->category('hidden-'.$index, $fields);
            $current->categories()->attach($category);
            $candidate->categories()->attach($category);
        }
        $this->get(route('blog.show', ['slug' => $current->code]))->assertOk()
            ->assertViewHas('related', fn ($posts): bool => $posts->isEmpty())
            ->assertDontSee('data-blog-related-articles', false);
    }

    public function test_exact_normalized_imported_tags_provide_real_relationships_without_keyword_guessing(): void
    {
        $current = $this->article('tagged-current', [], 'hr', ' Stari vijek, Rim ');
        $related = $this->article('matching-tags', [], 'hr', 'RIM; Edukacija');
        $this->article('substring-only', [], 'hr', 'Primorje');
        $this->article('unrelated-tags', [], 'hr', 'Matematika');
        $this->get(route('blog.show', ['slug' => $current->code]))->assertOk()
            ->assertViewHas('related', fn ($posts): bool => $posts->modelKeys() === [$related->id]);
    }

    public function test_popular_articles_use_numeric_source_reads_and_exclude_current_private_future_or_untranslated_posts(): void
    {
        $current = $this->article('popular-current', ['payload' => ['opencart' => ['count_read' => 999999]]]);
        $nine = $this->article('nine-reads', ['payload' => ['opencart' => ['count_read' => '9']]]);
        $hundred = $this->article('hundred-reads', ['payload' => ['opencart' => ['count_read' => '100']]]);
        $twenty = $this->article('twenty-reads', ['payload' => ['opencart' => ['count_read' => 20]]]);
        $this->article('private-popular', ['is_active' => false, 'payload' => ['opencart' => ['count_read' => 9999]]]);
        $this->article('future-popular', ['published_at' => now()->addDay(), 'payload' => ['opencart' => ['count_read' => 9999]]]);
        $this->article('italian-popular', ['payload' => ['opencart' => ['count_read' => 9999]]], 'it');
        $this->article('unknown-reads');
        $this->get(route('blog.show', ['slug' => $current->code]))->assertOk()
            ->assertViewHas('popular', fn ($posts): bool => $posts->modelKeys() === [$hundred->id, $twenty->id, $nine->id]
                && $posts->every(fn ($post): bool => $post->relationLoaded('media') && $post->relationLoaded('translations')))
            ->assertSee('data-blog-popular-articles', false)->assertSee('Najpopularniji članci');
        $this->assertSame(999999, data_get($current->fresh()->payload, 'opencart.count_read'));
    }

    public function test_share_links_use_the_article_url_without_tracking_queries_and_preserve_one_page_title(): void
    {
        $post = $this->article('share-an-article');
        $post->translations()->update(['title' => 'Povijest & igra', 'body_html' => '<p>Tekst članka za dijeljenje.</p>']);
        $url = route('blog.show', ['slug' => $post->code]);
        $response = $this->get($url.'?utm_source=test')->assertOk()
            ->assertSee('Tekst članka za dijeljenje.')->assertSee('blog-detail.css', false)->assertSee('blog-detail.js', false);
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//h1')->count());
        $this->assertSame(3, $xpath->query('//*[@data-blog-share]//a')->count());
        $this->assertSame($url, $xpath->query('//*[@data-blog-copy-url]')->item(0)->getAttribute('data-blog-copy-url'));
        $facebook = $xpath->query('//*[@data-blog-share]//a')->item(0);
        $this->assertSame('https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($url), $facebook->getAttribute('href'));
        $this->assertSame('noopener noreferrer', $facebook->getAttribute('rel'));
        $this->assertSame('_blank', $facebook->getAttribute('target'));
    }

    public function test_editorial_categories_are_portable_idempotent_and_preserve_admin_unlinks_after_article_reimport(): void
    {
        $current = $this->article('herrera-oc-blog-10820', ['payload' => ['opencart' => ['count_read' => 1870]]]);
        $other = $this->article('herrera-oc-blog-10733');
        $service = app(LibermediaBlogCategoryService::class);
        $this->assertSame(2, $service->apply());
        $history = Category::query()->where('code', 'libermedia-editorial-history')->firstOrFail();
        $this->assertSame(Category::SCOPE_BLOG, $history->scope);
        $this->assertSame('Povijest', $history->translations->first()->name);
        $this->assertSame(1870, data_get($current->fresh()->payload, 'opencart.count_read'));
        $this->assertSame(0, $service->apply());
        $history->translations()->update(['name' => 'Naša povijest']);
        $current->categories()->detach($history);
        $current->update(['payload' => ['opencart' => ['count_read' => 1900]]]);
        $this->assertSame(0, $service->apply());
        $this->assertFalse($current->categories()->whereKey($history)->exists());
        $this->assertTrue($other->categories()->whereKey($history)->exists());
        $this->assertSame('Naša povijest', $history->fresh()->translations->first()->name);
        $this->assertSame('editorial', data_get($history->fresh()->payload, LibermediaBlogCategoryService::PROVENANCE_KEY.'.posts.'.$current->code.'.mode'));
    }

    public function test_private_scheduled_and_untranslated_article_details_are_not_public(): void
    {
        foreach ([['is_active' => false], ['published_at' => now()->addDay()]] as $index => $fields) {
            $post = $this->article('not-public-'.$index, $fields);
            $this->get(route('blog.show', ['slug' => $post->code]))->assertNotFound();
        }
        $post = $this->article('wrong-language', [], 'it');
        $this->get(route('blog.show', ['slug' => $post->code]))->assertNotFound();
    }

    private function article(string $code, array $fields = [], string $locale = 'hr', string $tags = ''): BlogPost
    {
        $post = BlogPost::query()->create($fields + ['code' => $code, 'is_active' => true, 'published_at' => now()->subDay()]);
        $post->translations()->create(['locale' => $locale, 'title' => 'Članak '.$code, 'slug' => $code,
            'excerpt' => 'Uvod '.$code, 'body_html' => '<p>Sadržaj članka.</p>', 'payload' => ['opencart' => ['tags' => $tags]]]);

        return $post;
    }

    private function category(string $code, array $fields = []): Category
    {
        return Category::query()->create($fields + ['code' => $code, 'scope' => Category::SCOPE_BLOG, 'is_active' => true]);
    }
}
