<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Faq;
use App\Models\User;
use App\Services\Import\LavaggioContentImportService;
use App\Services\Settings\SystemSettingsService;
use Database\Seeders\LavaggioStorefrontSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class LavaggioContentImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $publicDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront.home' => 'lavaggio', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        $this->publicDirectory = storage_path('framework/testing/lavaggio-content-'.Str::uuid());
        File::ensureDirectoryExists($this->publicDirectory);
        $this->app->usePublicPath($this->publicDirectory);
        Storage::fake('public');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->publicDirectory);
        parent::tearDown();
    }

    public function test_import_is_idempotent_and_preserves_admin_catalog_and_unrelated_content(): void
    {
        $admin = User::factory()->create(['email' => 'tomislav@agmedia.hr', 'account_type' => 'staff', 'admin_login_enabled' => true]);
        $hash = $admin->password;
        $category = Category::create(['scope' => 'catalog', 'code' => 'existing-category', 'is_active' => true]);
        $unrelated = InfoPage::create(['code' => 'native-page']);
        $unrelated->translations()->create(['locale' => 'hr', 'title' => 'Lokalna stranica', 'slug' => 'lokalna-stranica', 'body_html' => '<p>Ručno uređeno</p>']);
        $snapshot = $this->snapshot();
        $importer = app(LavaggioContentImportService::class);
        $first = $importer->import($snapshot);
        $snapshot['resources'][0]['body_html'] = '<p>Ažuriran sadržaj</p>';
        $snapshot['resources'][0]['meta_description'] = 'Ažuriran SEO opis.';
        $second = $importer->import($snapshot);

        $this->assertSame($first['page_ids'], $second['page_ids']);
        $this->assertSame($first['blog_ids'], $second['blog_ids']);
        $this->assertDatabaseCount('content_info_pages', 3);
        $this->assertDatabaseCount('content_blog_posts', 1);
        $this->assertDatabaseCount('content_faqs', 1);
        $page = InfoPage::where('code', 'lavaggio-modx-20')->firstOrFail();
        $translation = $page->translations()->firstOrFail();
        $this->assertSame('Ažuriran SEO opis.', $translation->meta_description);
        $this->assertSame('<p>Ažuriran sadržaj</p>', $translation->body_html);
        $this->assertSame($admin->id, $page->created_by);
        $this->assertSame('usluge/pranje.html', data_get($page->payload, 'modx.uri'));
        $this->assertSame('Ručno uređeno', strip_tags($unrelated->fresh()->translations()->value('body_html')));
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertTrue($admin->fresh()->admin_login_enabled);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'code' => 'existing-category']);
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
    }

    public function test_two_pass_mapping_rewrites_relative_source_modx_and_query_id_links(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['url_overrides'] = [30 => '/faq'];
        $snapshot['resources'][0]['body_html'] = '<p><a href="../kontakt.html#telefon">Kontakt</a> '
            .'<a href="[[~30]]">ČPP</a> <a href="https://lavaggio.hr/index.php?id=30#pranje">Pitanja</a> '
            .'<a href="http://lavaggio.hr/">Početna</a><a href="https://www.lavaggio.hr/#usluge">Početna sekcija</a>'
            .'<a href="https://example.com/info">Vanjsko</a><a href="javascript:alert(1)">Loše</a></p>';
        app(LavaggioContentImportService::class)->import($snapshot);
        $body = InfoPage::where('code', 'lavaggio-modx-20')->firstOrFail()->translations()->value('body_html');

        $this->assertStringContainsString('href="/faq#telefon"', $body);
        $this->assertStringContainsString('href="/faq"', $body);
        $this->assertStringContainsString('href="/faq#pranje"', $body);
        $this->assertStringContainsString('href="/">Početna</a>', $body);
        $this->assertStringContainsString('href="/#usluge">Početna sekcija</a>', $body);
        $this->assertStringContainsString('href="https://example.com/info"', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('[[', $body);
    }

    public function test_images_srcsets_and_backgrounds_are_downloaded_once_and_blog_cover_is_editable_media(): void
    {
        $upload = UploadedFile::fake()->image('cover.png', 20, 20);
        $image = file_get_contents($upload->getRealPath());
        Http::fake(['https://www.lavaggio.hr/assets/*' => Http::response($image, 200, ['Content-Type' => 'image/png'])]);
        $snapshot = $this->snapshot();
        $snapshot['resources'][0]['body_html'] = '<div style="background-image:url(/assets/background.png)"><img data-src="/assets/cover.png" src="placeholder.png" srcset="/assets/cover.png 1x, /assets/large.png 2x" alt="Rublje"><img src="https://unlisted.invalid/image.png"></div>';
        $snapshot['resources'][2]['cover_url'] = 'https://www.lavaggio.hr/assets/cover.png';
        $first = app(LavaggioContentImportService::class)->import($snapshot);
        $second = app(LavaggioContentImportService::class)->import($snapshot);
        $body = InfoPage::where('code', 'lavaggio-modx-20')->firstOrFail()->translations()->value('body_html');
        $coverPath = 'assets/lavaggio/content/'.hash('sha256', 'https://www.lavaggio.hr/assets/cover.png').'.png';
        $this->assertSame(3, $first['images_local']);
        $this->assertSame(3, $second['images_local']);
        $this->assertStringContainsString('src="/'.$coverPath.'"', $body);
        $this->assertStringContainsString('srcset="/'.$coverPath.' 1x, /assets/lavaggio/content/', $body);
        $this->assertStringNotContainsString('style=', $body);
        $this->assertStringNotContainsString('unlisted.invalid', $body);
        $this->assertFileExists(public_path($coverPath));
        $post = BlogPost::where('code', 'lavaggio-modx-40')->firstOrFail();
        $this->assertSame(1, $post->getMedia('blog_cover')->count());
        $this->assertFileExists($post->getFirstMedia('blog_cover')->getPath());
        $this->assertSame('https://www.lavaggio.hr/assets/cover.png', $post->getFirstMedia('blog_cover')->getCustomProperty('lavaggio_source_url'));
        Http::assertSentCount(3);
    }

    public function test_rendered_body_wins_modx_variables_are_resolved_and_only_youtube_frames_survive(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['resources'][0]['content'] = '<p>Neupotrebljiv source</p>';
        $snapshot['resources'][0]['body_html'] = '<p>Raw body</p>';
        $snapshot['resources'][0]['rendered_body_html'] = '<h2>[[*pagetitle]]</h2><p class="lavaggio-content-intro old-source-class">[[*prvo]]</p><p class="source-purple">Običan odlomak</p><table style="color:red"><tr><td>Cijena 10 €</td></tr></table>'
            .'<script>alert(1)</script><style>body{display:none}</style><p onclick="alert(1)">Čisti sadržaj [[!SomeSnippet]]</p>'
            .'<iframe src="https://www.youtube.com/embed/Abc_def1234?autoplay=1" onload="alert(1)"></iframe>'
            .'<iframe width="315" height="560" src="https://www.youtube.com/embed/Portrait123"></iframe>'
            .'<iframe width="33157" height="560" src="https://www.youtube.com/embed/Badwidth123"></iframe>'
            .'<iframe src="https://evil.invalid/embed/a"></iframe>';
        $snapshot['resources'][0]['tvs'] = ['prvo' => 'Prenesen puni tekst'];
        $stats = app(LavaggioContentImportService::class)->import($snapshot);
        $body = InfoPage::where('code', 'lavaggio-modx-20')->firstOrFail()->translations()->value('body_html');

        $this->assertStringContainsString('<h2>Pranje rublja</h2>', $body);
        $this->assertStringContainsString('Prenesen puni tekst', $body);
        $this->assertStringContainsString('<p class="lavaggio-content-intro">Prenesen puni tekst</p>', $body);
        $this->assertStringNotContainsString('old-source-class', $body);
        $this->assertStringNotContainsString('source-purple', $body);
        $this->assertStringContainsString('Cijena 10 €', $body);
        $this->assertStringContainsString('<table>', $body);
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/Abc_def1234', $body);
        $this->assertStringContainsString('width="315" height="560"', $body);
        $this->assertStringContainsString('class="lavaggio-content-video lavaggio-content-video-portrait"', $body);
        $this->assertSame(1, substr_count($body, 'lavaggio-content-video-portrait'));
        $this->assertStringNotContainsString('33157', $body);
        $this->assertStringNotContainsString('autoplay', $body);
        $this->assertStringNotContainsString('evil.invalid', $body);
        $this->assertStringNotContainsString('onload', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('<style', $body);
        $this->assertStringNotContainsString('[[', $body);
        $this->assertStringNotContainsString('Raw body', $body);
        $this->assertSame(1, $stats['modx_tags_removed']);
    }

    public function test_skipped_native_resources_still_resolve_from_overrides_and_source_aliases(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['url_overrides'] = [1 => '/', 2 => '/contact', 52 => '/blog', 72 => '/faq'];
        $snapshot['source_resources'] = [
            ['id' => 1, 'uri' => 'home.html', 'alias' => 'home', 'source_url' => 'https://www.lavaggio.hr/'],
            ['id' => 2, 'uri' => 'kontakt-praonica.html', 'alias' => 'kontakt-praonica', 'source_url' => 'https://www.lavaggio.hr/kontakt-praonica.html'],
        ];
        $snapshot['resources'][0]['body_html'] = '<p><a href="[[~2]]">Kontakt</a><a href="/kontakt-praonica.html">Kontakt alias</a><a href="[[~1]]">Početna</a><a href="[[~999]]">Staro</a></p>';
        $stats = app(LavaggioContentImportService::class)->import($snapshot);
        $body = InfoPage::where('code', 'lavaggio-modx-20')->firstOrFail()->translations()->value('body_html');
        $this->assertSame(2, substr_count($body, 'href="/contact"'));
        $this->assertStringContainsString('href="/"', $body);
        $this->assertStringContainsString('href="https://www.lavaggio.hr/index.php?id=999"', $body);
        $this->assertContains('Unresolved MODX resource link ID 999.', $stats['warnings']);
        $this->assertSame('/contact', $stats['resource_urls'][2]);
        $this->assertDatabaseCount('content_info_pages', 2);
    }

    public function test_links_to_explicitly_inactive_resources_are_unwrapped_without_losing_text_or_children(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['inactive_resource_ids'] = [57, 58, 74, 77, 79, 339];
        $snapshot['resources'][] = ['id' => 77, 'type' => 'page', 'pagetitle' => 'Neobjavljen program', 'uri' => 'neobjavljen-program.html', 'body_html' => '<p>Ne smije se ponovno objaviti.</p>'];
        $snapshot['resources'][0]['body_html'] = '<p>Pročitajte <a href="[[~77]]"><strong>program vjernosti</strong> i pogodnosti</a>. '
            .'<a href="https://www.lavaggio.hr/index.php?id=57#uvjeti"><em>Stari uvjeti</em></a> ostaju u tekstu. '
            .'<a href="/index.php?id=339">Profesionalno pranje</a> '
            .'<a href="[[~30]]">Aktivni kontakt</a><a href="[[~999]]">Nepoznati resurs</a></p>';
        $stats = app(LavaggioContentImportService::class)->import($snapshot);
        $body = InfoPage::where('code', 'lavaggio-modx-20')->firstOrFail()->translations()->value('body_html');

        $this->assertStringContainsString('<strong>program vjernosti</strong> i pogodnosti', $body);
        $this->assertStringContainsString('<em>Stari uvjeti</em> ostaju u tekstu.', $body);
        $this->assertStringContainsString('Profesionalno pranje', $body);
        $this->assertStringContainsString('href="/page/kontakt">Aktivni kontakt</a>', $body);
        $this->assertStringContainsString('href="https://www.lavaggio.hr/index.php?id=999"', $body);
        $this->assertSame(2, substr_count($body, '<a '));
        $this->assertStringNotContainsString('id=57', $body);
        $this->assertStringNotContainsString('id=77', $body);
        $this->assertStringNotContainsString('id=339', $body);
        $this->assertStringNotContainsString('href=""', $body);
        $this->assertSame(3, $stats['inactive_links_removed']);
        $this->assertContains('Removed 3 links to inactive MODX resources; their text and content were preserved.', $stats['warnings']);
        $this->assertContains('Unresolved MODX resource link ID 999.', $stats['warnings']);
        $this->assertCount(2, $stats['warnings']);
        $this->assertDatabaseCount('content_info_pages', 2);
        $this->assertDatabaseMissing('content_info_pages', ['code' => 'lavaggio-modx-77']);
    }

    public function test_unrelated_slug_collision_is_refused_before_writing_content(): void
    {
        $page = InfoPage::create(['code' => 'native']);
        $page->translations()->create(['locale' => 'hr', 'title' => 'Native', 'slug' => 'pranje', 'body_html' => '<p>Preserve</p>']);
        try {
            app(LavaggioContentImportService::class)->import($this->snapshot());
            $this->fail('The unrelated slug collision was not detected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unrelated local content', $exception->getMessage());
        }
        $this->assertDatabaseCount('content_info_pages', 1);
        $this->assertSame('<p>Preserve</p>', $page->translations()->value('body_html'));
    }

    public function test_imported_pages_faqs_and_tv_studio_render_with_native_routes_and_seo(): void
    {
        $this->app->usePublicPath(base_path('public'));
        config(['app.name' => 'Lavaggio', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia']);
        $this->seed(LavaggioStorefrontSeeder::class);
        app(SystemSettingsService::class)->put('catalog_use_blog', true);
        app(LavaggioContentImportService::class)->import($this->snapshot());

        $this->get('/page/pranje')->assertOk()->assertSee('Izvorni sadržaj pranja')
            ->assertSee('<title>Pranje i peglanje | Lavaggio</title>', false)
            ->assertSee('content="Opis za tražilice."', false);
        $this->get('/blog')->assertOk()->assertSee('TV studio')->assertSee('Ozon u praksi')->assertDontSee('Ideje za igru');
        $this->get('/blog/ozon-u-praksi')->assertOk()->assertSee('TV studio')->assertSee('Video i savjeti o ozonu.');
        $this->get('/faq')->assertOk()->assertSee('Kako prati rublje?')->assertSee('Slijedite upute na odjeći.');
        $this->assertSame('Kako prati rublje?', Faq::firstOrFail()->translations()->value('question'));
    }

    public function test_command_previews_without_mutations_and_refuses_nonlocal_database_connections(): void
    {
        $path = $this->publicDirectory.'/snapshot.json';
        File::put($path, json_encode($this->snapshot(), JSON_THROW_ON_ERROR));
        $this->artisan('lavaggio:import-content', ['snapshot' => $path])->expectsOutputToContain('Preview only')->assertSuccessful();
        $this->assertDatabaseCount('content_info_pages', 0);
        $this->artisan('lavaggio:import-content', ['snapshot' => $path, '--execute' => true])->assertSuccessful();
        $this->assertDatabaseCount('content_info_pages', 2);
        config(['database.default' => 'mysql']);
        try {
            $this->artisan('lavaggio:import-content', ['snapshot' => $path, '--execute' => true])->expectsOutputToContain('restricted to the local Lavaggio SQLite')->assertFailed();
        } finally {
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_native_faq_uses_editable_lavaggio_source_intro_and_seo_without_changing_other_brands(): void
    {
        $this->app->usePublicPath(base_path('public'));
        config(['app.name' => 'Lavaggio', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia']);
        $this->seed(LavaggioStorefrontSeeder::class);
        $snapshot = $this->snapshot();
        $snapshot['resources'][] = [
            'id' => 72, 'type' => 'page', 'pagetitle' => 'Pitanja i odgovori', 'uri' => 'pitanja-i-odgovori',
            'meta_title' => 'Odgovori iz praonice | Lavaggio', 'meta_description' => 'Pranje, sušenje i korištenje praonice.',
            'body_html' => '<p>Za dodatna pitanja <a href="[[~30]]">kontaktirajte praonicu</a>.</p>',
        ];
        app(LavaggioContentImportService::class)->import($snapshot);
        $this->get('/faq')->assertOk()
            ->assertSee('<title>Odgovori iz praonice | Lavaggio</title>', false)
            ->assertSee('content="Pranje, sušenje i korištenje praonice."', false)
            ->assertSee('Pitanja i odgovori')->assertSee('Za dodatna pitanja')
            ->assertSee('href="/page/kontakt">kontaktirajte praonicu</a>', false)
            ->assertSee('Kako prati rublje?')->assertSee('Slijedite upute na odjeći.')
            ->assertDontSee('Odgovori na najčešća pitanja kupaca.');

        InfoPage::where('code', 'lavaggio-modx-72')->firstOrFail()->translations()->where('locale', 'hr')->update([
            'body_html' => '<p>Uređen uvod iz administracije.</p>',
        ]);
        $this->get('/faq')->assertOk()->assertSee('Uređen uvod iz administracije.');
        config(['storefront.home' => 'herrera']);
        $this->get('/faq')->assertOk()->assertViewHas('faqPageTranslation', null)
            ->assertSee('Odgovori na najčešća pitanja kupaca.')->assertDontSee('Uređen uvod iz administracije.');
    }

    private function snapshot(): array
    {
        return [
            'resources' => [
                ['id' => 20, 'parent' => 10, 'type' => 'page', 'pagetitle' => 'Pranje rublja', 'longtitle' => 'Pranje i peglanje | Lavaggio', 'uri' => 'usluge/pranje.html', 'alias' => 'pranje', 'body_html' => '<p>Izvorni sadržaj pranja</p>', 'meta_description' => 'Opis za tražilice.', 'publishedon' => 1700000000],
                ['id' => 30, 'parent' => 0, 'type' => 'page', 'pagetitle' => 'Kontakt', 'uri' => 'kontakt.html', 'body_html' => '<p>Kontaktirajte nas.</p>'],
                ['id' => 40, 'parent' => 15, 'type' => 'blog', 'pagetitle' => 'Ozon u praksi', 'uri' => 'blog/ozon-u-praksi.html', 'body_html' => '<p>Video i savjeti o ozonu.</p>', 'introtext' => 'Savjeti iz praonice.', 'publishedon' => 1700000000],
            ],
            'faqs' => [['id' => '1', 'question' => 'Kako prati rublje?', 'answer_html' => '<p>Slijedite upute na odjeći.</p>']],
        ];
    }
}
