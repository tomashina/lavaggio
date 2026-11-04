<?php

namespace Tests\Feature\Import;

use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Faq;
use App\Services\Front\NavigationMenuService;
use App\Services\Import\LavaggioFeedbackUpdateService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Lavaggio\NovemberFeedback;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LavaggioNovemberFeedbackMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_real_migration_reconciles_imported_pages_pricing_navigation_faq_and_hours(): void
    {
        [$pages, $faqs] = $this->importedContent();
        $settings = app(SystemSettingsService::class);
        $settings->put('front_navigation_main', [
            ['type' => 'page', 'page_id' => $pages[70]->id, 'label' => 'Stari cjenik', 'children' => [
                ['type' => 'page', 'page_id' => $pages[87]->id, 'label' => 'Samoposlužna'],
                ['type' => 'page', 'page_id' => $pages[322]->id, 'label' => 'Poklon bon'],
            ]],
            ['type' => 'page', 'page_id' => $pages[69]->id, 'label' => 'Samoposlužna praonica'],
            ['type' => 'custom', 'label' => 'Kontakt', 'url' => '/contact'],
        ]);
        $settings->put('store_footer_hours', '');
        $beforePrice = $pages[88]->translations()->sole()->body_html;

        $this->migration()->up();

        foreach (NovemberFeedback::RETIRED_PAGES as $sourceId) {
            $this->assertFalse($pages[$sourceId]->fresh()->is_active, 'Page '.$sourceId.' must be retired.');
        }
        $this->assertTrue($pages[88]->fresh()->is_active);
        $afterPrice = $pages[88]->translations()->sole()->body_html;
        $this->assertStringContainsString('Cijene vrijede od: 10.09.2026', $afterPrice);
        preg_match('#<table\b[^>]*>.*?</table>#s', $beforePrice, $oldTable);
        preg_match('#<table\b[^>]*>.*?</table>#s', $afterPrice, $newTable);
        $this->assertSame($oldTable[0], $newTable[0]);
        $nav = $settings->get(NavigationMenuService::SETTINGS_KEY);
        $this->assertCount(2, $nav);
        $this->assertSame($pages[88]->id, $nav[0]['page_id']);
        $this->assertSame('Cjenik', $nav[0]['label']);
        $this->assertSame([], $nav[0]['children']);
        $this->assertSame('/kontakt', $nav[1]['url']);
        $this->assertSame('Pon-Pet: 08h - 16h', $settings->get('store_footer_hours'));
        foreach (['72-1', '72-6'] as $id) {
            $this->assertFalse($faqs[$id]->fresh()->is_active);
        }
        $this->assertSame(11, Faq::query()->where('is_active', true)->count());
        $hours = $faqs['72-3']->translations()->sole()->answer_html;
        $this->assertStringContainsString('od 08h do 16h', $hours);
        $this->assertStringNotContainsString('Samoposlužna', $hours);
        $this->assertStringContainsString('/stranica/radno-vrijeme-praonice-rublja', $hours);
        $this->assertStringContainsString('href="/cjenik"', $faqs['72-4']->translations()->sole()->answer_html);
        foreach ([68, 80, 174] as $id) {
            $translation = $pages[$id]->translations()->sole();
            $this->assertStringNotContainsString('samopos', mb_strtolower((string) $translation->meta_description));
        }
        $this->assertStringNotContainsString('SAMOPOSLUŽNA PRAONICA', $pages[86]->translations()->sole()->body_html);
        $this->assertStringNotContainsString('Samoposlužna', $pages[174]->translations()->sole()->body_html);
        $this->assertSame([], app(LavaggioFeedbackUpdateService::class)->plan()['actions']);
    }

    public function test_idempotent_up_and_down_preserve_custom_copy_hours_seo_and_prices(): void
    {
        [$pages, $faqs] = $this->importedContent();
        $customHoursBody = '<p>Klijentov prilagođeni povijesni opis samoposlužne praonice.</p>';
        $customAnswer = '<p>Klijentov prilagođeni odgovor o samoposlužnoj iz povijesti.</p>';
        $pages[174]->translations()->update(['body_html' => $customHoursBody, 'meta_description' => 'Klijentov SEO opis']);
        $faqs['72-3']->translations()->update(['answer_html' => $customAnswer]);
        $customPrice = '<table><tr><td>Posebna usluga</td><td>123,45 € / kg</td></tr></table><p>Cijene vrijede od: 01.06.2026</p>';
        $pages[88]->translations()->update(['body_html' => $customPrice, 'title' => 'Klijentov cjenik']);
        app(SystemSettingsService::class)->put('store_footer_hours', 'Pon-Pet: 09h - 17h');
        $migration = $this->migration();
        $migration->up();
        $afterFirst = $this->snapshot();
        $migration->up();
        $migration->down();

        $this->assertSame($afterFirst, $this->snapshot());
        $this->assertSame($customHoursBody, $pages[174]->translations()->sole()->body_html);
        $this->assertSame('Klijentov SEO opis', $pages[174]->translations()->sole()->meta_description);
        $this->assertSame($customAnswer, $faqs['72-3']->translations()->sole()->answer_html);
        $this->assertSame(str_replace('01.06.2026', '10.09.2026', $customPrice), $pages[88]->translations()->sole()->body_html);
        $this->assertSame('Klijentov cjenik', $pages[88]->translations()->sole()->title);
        $this->assertSame('Pon-Pet: 09h - 17h', app(SystemSettingsService::class)->get('store_footer_hours'));
        $this->assertCount(2, app(LavaggioFeedbackUpdateService::class)->plan()['skipped']);
    }

    public function test_other_storefront_is_not_changed(): void
    {
        $this->importedContent();
        config(['storefront.home' => 'watchline']);
        $before = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_fresh_install_without_imported_pages_is_not_changed(): void
    {
        app(SystemSettingsService::class)->put('store_footer_hours', '');
        $before = $this->snapshot();
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_failure_while_saving_hours_rolls_back_prior_content_changes(): void
    {
        $this->importedContent();
        $before = $this->snapshot();
        DB::unprepared("CREATE TEMP TRIGGER reject_feedback_hours BEFORE INSERT ON system_settings WHEN NEW.key = 'store_footer_hours' BEGIN SELECT RAISE(ABORT, 'feedback migration atomicity test'); END");
        try {
            $this->migration()->up();
            $this->fail('A settings failure must roll back earlier page and FAQ changes.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('feedback migration atomicity test', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_feedback_hours');
        }
        $this->assertSame($before, $this->snapshot());
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_04_120000_sync_lavaggio_november_feedback.php');
    }

    private function importedContent(): array
    {
        $snapshot = json_decode(file_get_contents(database_path('data/lavaggio-content-20261028.json')), true, flags: JSON_THROW_ON_ERROR);
        $resources = collect($snapshot['resources'])->keyBy('id');
        $pages = [];
        foreach (array_unique([...NovemberFeedback::RETIRED_PAGES, 68, 71, 80, 86, 88, 174]) as $id) {
            $pages[$id] = InfoPage::create(['code' => 'lavaggio-modx-'.$id, 'is_active' => true]);
        }
        $urls = collect($pages)->map(fn ($page, $id) => '/page/'.$resources[$id]['alias'])->all();
        $urls[2] = '/contact';
        $html = fn ($body) => preg_replace_callback('/\[\[~(\d+)\]\]/', fn ($match) => $urls[(int) $match[1]] ?? '#', $body);
        foreach ($pages as $id => $page) {
            $source = $resources[$id];
            $page->translations()->create([
                'locale' => 'hr', 'slug' => $source['alias'], 'title' => $source['pagetitle'],
                'body_html' => $html($source['body_html']), ...NovemberFeedback::metadataDefaults($source),
            ]);
        }
        $faqs = [];
        foreach ($snapshot['faqs'] as $source) {
            $faq = Faq::create(['code' => 'lavaggio-modx-faq-'.$source['id'], 'group_code' => 'lavaggio', 'is_active' => true]);
            $faq->translations()->create(['locale' => 'hr', 'slug' => 'faq-'.$source['id'], 'question' => $source['question'], 'answer_html' => $html($source['answer_html'])]);
            $faqs[$source['id']] = $faq;
        }

        return [$pages, $faqs];
    }

    private function snapshot(): array
    {
        $data = [];
        foreach (['content_info_pages', 'content_info_page_translations', 'content_faqs', 'content_faq_translations', 'system_settings'] as $table) {
            $data[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $data;
    }
}
