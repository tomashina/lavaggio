<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Blog\BlogPost;
use App\Services\Import\LavaggioContentImportService;
use App\Services\Settings\SystemSettingsService;
use Database\Seeders\LavaggioStorefrontSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LavaggioBlogCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.name' => 'Lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        $this->seed(LavaggioStorefrontSeeder::class);
        app(SystemSettingsService::class)->put('catalog_use_blog', true);
        Http::preventStrayRequests();
        Storage::fake('public');
    }

    public function test_source_tags_become_editable_blog_categories_with_all_multi_category_assignments(): void
    {
        $catalogCategory = Category::create(['scope' => 'catalog', 'code' => 'catalog-savjeti', 'is_active' => true]);
        $catalogCategory->translations()->create(['scope' => 'catalog', 'locale' => 'hr', 'name' => 'Savjeti za pranje', 'slug' => 'savjeti-za-pranje']);
        $snapshot = ['resources' => [
            $this->resource(1, 'Savjeti za pranje, Posteljno rublje,Savjeti za pranje'),
            $this->resource(2, 'Priprema sezone'),
            $this->resource(3, ''),
        ]];
        $result = app(LavaggioContentImportService::class)->import($snapshot);
        $this->assertSame(3, $result['blog_categories_imported']);
        $this->assertDatabaseCount('content_blog_posts', 3);
        $this->assertSame(3, Category::where('scope', 'blog')->count());
        $this->assertSame(1, Category::where('scope', 'catalog')->count());
        $post = BlogPost::where('code', 'lavaggio-modx-1')->firstOrFail();
        $this->assertSame(['Posteljno rublje', 'Savjeti za pranje'], $post->categories->map(fn ($category) => $category->translations()->value('name'))->sort()->values()->all());
        $this->assertTrue((bool) $post->categories()->where('code', 'lavaggio-blog-savjeti-za-pranje')->firstOrFail()->pivot->is_primary);
        $this->assertSame(0, BlogPost::where('code', 'lavaggio-modx-3')->firstOrFail()->categories()->count());
        $this->assertSame('Savjeti za pranje, Posteljno rublje,Savjeti za pranje', data_get($post->payload, 'modx.tvs.tag'));
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_reimport_retains_admin_category_renames_removals_and_native_assignments(): void
    {
        $snapshot = ['resources' => [$this->resource(1, 'Savjeti za pranje,Posteljno rublje')]];
        $importer = app(LavaggioContentImportService::class);
        $importer->import($snapshot);
        $post = BlogPost::where('code', 'lavaggio-modx-1')->firstOrFail();
        $advice = Category::where('code', 'lavaggio-blog-savjeti-za-pranje')->firstOrFail();
        $bedding = Category::where('code', 'lavaggio-blog-posteljno-rublje')->firstOrFail();
        $advice->translations()->update(['name' => 'Savjeti iz praonice']);
        $post->categories()->detach($bedding);
        $native = Category::create(['scope' => 'blog', 'code' => 'admin-category', 'is_active' => true]);
        $native->translations()->create(['scope' => 'blog', 'locale' => 'hr', 'name' => 'Administratorski odabir', 'slug' => 'administratorski-odabir']);
        $post->categories()->attach($native->id, ['sort_order' => 2, 'is_primary' => false]);
        $importer->import($snapshot);

        $this->assertSame('Savjeti iz praonice', $advice->fresh()->translations()->value('name'));
        $this->assertSame([$advice->id, $native->id], $post->fresh()->categories()->orderBy('categories.id')->pluck('categories.id')->all());
        $this->assertSame(3, Category::where('scope', 'blog')->count());
        $this->assertDatabaseCount('content_blog_post_category', 2);
        $this->get('/blog?tag=Savjeti+za+pranje')->assertOk()->assertSee('Savjeti iz praonice')
            ->assertViewHas('posts', fn ($posts): bool => $posts->total() === 1);
    }

    public function test_lavaggio_archive_has_twelve_thumbnail_cards_dates_badges_and_native_or_source_tag_filters(): void
    {
        $resources = [];
        for ($index = 0; $index < 13; $index++) {
            $resources[] = $this->resource($index + 1, $index === 0 ? 'Savjeti za pranje,Posteljno rublje' : ($index === 1 ? 'Savjeti za pranje' : 'Posteljno rublje'));
        }
        app(LavaggioContentImportService::class)->import(['resources' => $resources]);
        $first = BlogPost::where('code', 'lavaggio-modx-1')->firstOrFail();
        config(['media_profiles.models.'.BlogPost::class.'.collections.blog_cover.conversions' => []]);
        $first->addMedia(UploadedFile::fake()->image('video-cover.jpg', 480, 360))->toMediaCollection('blog_cover');
        $response = $this->get('/blog')->assertOk()
            ->assertSee('lavaggio-blog.css')->assertSee('data-lavaggio-blog-grid', false)
            ->assertSee('Savjeti za pranje')->assertSee('Posteljno rublje')->assertSee('12. 06. 2026.')
            ->assertSee('Izvorni sažetak objave 1.')->assertDontSee('<iframe', false)
            ->assertViewHas('posts', fn ($posts): bool => $posts->perPage() === 12 && $posts->count() === 12 && $posts->total() === 13);
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(12, $xpath->query('//*[@data-lavaggio-blog-card]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-lavaggio-blog-card]//img')->length);
        $this->assertSame(3, $xpath->query('//select[@id="lavaggio-blog-category"]/option')->length);
        $this->assertSame(1, $xpath->query('//*[@data-lavaggio-blog-card][1]//a[contains(@class,"lavaggio-blog-category-badge") and contains(text(),"Savjeti za pranje")]')->length);

        foreach (['/blog?category=savjeti-za-pranje', '/blog?tag=Savjeti+za+pranje'] as $url) {
            $this->get($url)->assertOk()->assertSee('Prikaži sve')
                ->assertSee('Objava 1')->assertSee('Objava 2')->assertDontSee('Objava 3')
                ->assertViewHas('posts', fn ($posts): bool => $posts->total() === 2)
                ->assertViewHas('blogCategoryTranslation', fn ($translation): bool => $translation->name === 'Savjeti za pranje');
        }
        $this->get('/blog?category=posteljno-rublje')->assertOk()->assertSee('Objava 1')->assertDontSee('Objava 2')
            ->assertViewHas('posts', fn ($posts): bool => $posts->total() === 12);
        $this->get('/blog?tag=Nepostojeća')->assertNotFound();
    }

    public function test_category_visibility_changes_remove_filter_option_and_badge_without_hiding_articles(): void
    {
        app(LavaggioContentImportService::class)->import(['resources' => [$this->resource(1, 'Savjeti za pranje')]]);
        Category::where('code', 'lavaggio-blog-savjeti-za-pranje')->update(['is_active' => false]);
        $this->get('/blog')->assertOk()->assertSee('Objava 1')->assertDontSee('lavaggio-blog-category-badge')
            ->assertViewHas('blogFilterCategories', fn ($categories): bool => $categories->isEmpty());
        $this->get('/blog?category=savjeti-za-pranje')->assertNotFound();
        $this->get('/blog?tag=Savjeti+za+pranje')->assertNotFound();
    }

    public function test_lavaggio_source_video_uses_cover_on_cards_and_does_not_repeat_it_above_the_player(): void
    {
        app(LavaggioContentImportService::class)->import(['resources' => [$this->resource(1, 'Savjeti za pranje')]]);
        $post = BlogPost::where('code', 'lavaggio-modx-1')->firstOrFail();
        config(['media_profiles.models.'.BlogPost::class.'.collections.blog_cover.conversions' => []]);
        $cover = $post->addMedia(UploadedFile::fake()->image('source-video-cover.jpg', 480, 360))->toMediaCollection('blog_cover');
        $response = $this->get('/blog/objava-1')->assertOk()->assertSee('youtube-nocookie.com/embed/Abcdef12345', false);
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);
        $this->assertSame(1, $xpath->query('//article[contains(@class,"blog-article")]//iframe')->length);
        $this->assertSame(0, $xpath->query('//article[contains(@class,"blog-article")]//figure/img')->length);
        $this->get('/blog')->assertOk()->assertSee($cover->getUrl(), false);

        config(['storefront.home' => 'libermedia']);
        $response = $this->get('/blog/objava-1')->assertOk();
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $this->assertSame(1, (new DOMXPath($document))->query('//article[contains(@class,"blog-article")]//figure/img')->length);
    }

    private function resource(int $id, string $tags): array
    {
        return [
            'id' => $id, 'type' => 'blog', 'pagetitle' => 'Objava '.$id, 'uri' => 'blog/objava-'.$id,
            'introtext' => 'Izvorni sažetak objave '.$id.'.', 'publishedon' => 1781283600 - ($id - 1) * 86400,
            'body_html' => '<iframe width="315" height="560" src="https://www.youtube.com/embed/Abcdef12345"></iframe><p>Izvorni sadržaj.</p>',
            'tvs' => ['tag' => $tags],
        ];
    }
}
