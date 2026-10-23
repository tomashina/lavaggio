<?php

namespace Tests\Feature\Front;

use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\Page\InfoPage;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibermediaRichtextTablesTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = '<table class="table table-striped"><caption>Usporedba materijala</caption><thead><tr><th scope="col">Materijal</th><th scope="col">Prednost</th></tr></thead><tbody><tr><th scope="row" rowspan="2">Drvo</th><td>Trajnost</td></tr><tr><td>Prirodan materijal</td></tr><tr><td colspan="2"><a href="/shop">Istraži ponudu</a></td></tr></tbody></table>';

    private const LISTS = '<ul><li>Prva stavka<ul><li>Podstavka</li></ul></li></ul><ol start="4"><li>Četvrti korak</li><li value="7">Sedmi korak</li></ol>';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Liber Media', 'catalog_use_blog' => true]);
    }

    public function test_blog_tables_keep_their_original_semantics_and_receive_the_shared_editorial_assets(): void
    {
        $post = BlogPost::query()->create(['code' => 'table-blog', 'is_active' => true, 'published_at' => now()->subDay()]);
        $translation = $post->translations()->create(['locale' => 'hr', 'title' => 'Članak s tablicom', 'slug' => 'table-blog', 'body_html' => self::TABLE.self::LISTS]);

        $response = $this->get(route('blog.show', ['slug' => 'table-blog']))->assertOk()
            ->assertSee('libermedia-richtext-tables.css', false)->assertSee('libermedia-richtext-tables.js', false)
            ->assertSee('libermedia-richtext-lists.css', false);
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);
        $table = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," content-richtext ")]//table')->item(0);
        $this->assertNotNull($table);
        $this->assertSame('table table-striped', $table->getAttribute('class'));
        $this->assertSame(1, $xpath->query('.//caption', $table)->count());
        $this->assertSame(2, $xpath->query('.//thead//th[@scope="col"]', $table)->count());
        $this->assertSame(1, $xpath->query('.//th[@rowspan="2"]', $table)->count());
        $this->assertSame(1, $xpath->query('.//td[@colspan="2"]', $table)->count());
        $this->assertSame(1, $xpath->query('.//ul/li/ul/li', $table->parentNode)->count());
        $this->assertSame(1, $xpath->query('.//ol[@start="4"]/li[@value="7"]', $table->parentNode)->count());
        $this->assertSame(self::TABLE.self::LISTS, $translation->fresh()->body_html);
    }

    public function test_info_pages_and_managed_richtext_share_the_editorial_table_styles(): void
    {
        $page = InfoPage::query()->create(['code' => 'table-info', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'title' => 'Informacije s tablicom', 'slug' => 'table-info', 'body_html' => self::TABLE]);
        $block = ContentBlock::query()->create(['code' => 'table-content', 'name' => 'Tablica sadržaja', 'type' => 'rich_text', 'is_active' => true]);
        $block->translations()->create(['locale' => 'hr', 'title' => 'Sadržaj s tablicom', 'body_html' => self::TABLE]);
        $block->slots()->create(['placement' => 'page.bottom', 'frontend_variant' => 'desktop', 'is_active' => true]);

        $this->get(route('pages.show', ['slug' => 'table-info']))->assertOk()
            ->assertSee('libermedia-richtext-tables.css', false)->assertSee('libermedia-richtext-tables.js', false)
            ->assertSee('data-richtext-content', false)->assertSee('Sadržaj s tablicom');

    }

    public function test_other_storefronts_do_not_load_liber_media_table_assets(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $this->get(route('home'))->assertOk()
            ->assertDontSee('libermedia-richtext-tables.css', false)->assertDontSee('libermedia-richtext-tables.js', false)
            ->assertDontSee('libermedia-richtext-lists.css', false);
    }
}
