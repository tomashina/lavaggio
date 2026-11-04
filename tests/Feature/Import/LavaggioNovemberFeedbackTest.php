<?php

namespace Tests\Feature\Import;

use App\Models\Content\Page\InfoPage;
use App\Services\Import\LavaggioFeedbackUpdateService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Lavaggio\NovemberFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LavaggioNovemberFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_preview_does_not_write_and_apply_preserves_custom_prices_and_copy_and_is_idempotent(): void
    {
        $body = '<h2>Moj uređeni cjenik</h2><table><tr><td>Posebna usluga</td><td>123,45 € / kg</td></tr></table><p>Cijene vrijede od: 01.06.2026</p>';
        $page = InfoPage::create(['code' => 'lavaggio-modx-88', 'is_active' => true]);
        $translation = $page->translations()->create(['locale' => 'hr', 'slug' => 'cjenik-usluzno-pranje', 'title' => 'Uređeni naslov', 'body_html' => $body]);
        $retired = InfoPage::create(['code' => 'lavaggio-modx-69', 'is_active' => true]);
        $settings = app(SystemSettingsService::class);
        $settings->put('lavaggio_home_title', 'Njega | Savjeti');
        $settings->put('store_footer_hours', 'Posebno radno vrijeme');
        $settings->put('lavaggio_custom_links', [['label' => 'Njega | Savjeti', 'url' => '/contact#podrska']]);
        $this->artisan('lavaggio:apply-november-feedback')->assertSuccessful();
        $this->assertTrue($retired->fresh()->is_active);
        $this->assertSame($body, $translation->fresh()->body_html);
        $service = app(LavaggioFeedbackUpdateService::class);
        $plan = $service->plan();
        DB::transaction(fn () => $service->apply($plan));
        $this->assertFalse($retired->fresh()->is_active);
        $this->assertSame(str_replace('01.06.2026', '10.09.2026', $body), $translation->fresh()->body_html);
        $this->assertSame('Uređeni naslov', $translation->fresh()->title);
        $this->assertSame('Njega | Savjeti', $settings->get('lavaggio_home_title'));
        $this->assertSame('Posebno radno vrijeme', $settings->get('store_footer_hours'));
        $this->assertSame([['label' => 'Njega | Savjeti', 'url' => '/kontakt#podrska']], $settings->get('lavaggio_custom_links'));
        $this->assertSame([], $service->plan()['actions']);
    }

    public function test_custom_opening_hours_content_is_preserved_and_flagged_for_review(): void
    {
        $page = InfoPage::create(['code' => 'lavaggio-modx-174', 'is_active' => true]);
        $body = '<p>Moj novi opis samoposlužne praonice koji se ne smije prepisati.</p>';
        $translation = $page->translations()->create(['locale' => 'hr', 'slug' => 'radno-vrijeme', 'title' => 'Radno vrijeme', 'body_html' => $body]);
        $service = app(LavaggioFeedbackUpdateService::class);
        $plan = $service->plan();
        $service->apply($plan);
        $this->assertSame($body, $translation->fresh()->body_html);
        $this->assertNotEmpty($plan['skipped']);
    }

    public function test_source_defaults_keep_the_entire_price_table_and_change_only_the_confirmed_date(): void
    {
        $snapshot = json_decode(file_get_contents(database_path('data/lavaggio-content-20261028.json')), true, flags: JSON_THROW_ON_ERROR);
        $source = collect($snapshot['resources'])->firstWhere('id', 88);
        $updated = NovemberFeedback::resource($source);
        $this->assertSame(str_replace('Cijene vrijede od: 01.06.2026', 'Cijene vrijede od: 10.09.2026', $source['body_html']), $updated['body_html']);
        $next = NovemberFeedback::snapshot($snapshot);
        foreach ([69, 87, 92, 154, 155, 322] as $id) {
            $this->assertContains($id, $next['inactive_resource_ids']);
        }
        $this->assertSame('/cjenik', $next['url_overrides'][88]);
        $this->assertNotContains('72-1', array_column($next['faqs'], 'id'));
    }

    public function test_link_updates_preserve_external_links_and_unwrap_retired_blog_links(): void
    {
        $service = app(LavaggioFeedbackUpdateService::class);
        $external = '<p><a href="https://example.org/page/samoposluzna-praonica-rublja">Vanjski sadržaj</a></p>';
        $this->assertSame($external, $service->html($external));
        $blog = '<p><a href="/stranica/samoposluzna-praonica-rublja">Povijesni tekst</a></p>';
        $this->assertSame('<p>Povijesni tekst</p>', $service->html($blog, false));
        $this->assertSame('<p></p>', $service->html($blog));
    }

    public function test_original_derived_metadata_is_updated_and_custom_seo_and_page_body_are_preserved(): void
    {
        $snapshot = json_decode(file_get_contents(database_path('data/lavaggio-content-20261028.json')), true, flags: JSON_THROW_ON_ERROR);
        $sources = collect($snapshot['resources'])->keyBy('id');
        $translations = [];
        foreach ([68, 80] as $id) {
            $source = $sources[$id];
            $page = InfoPage::create(['code' => 'lavaggio-modx-'.$id, 'is_active' => true]);
            $translations[$id] = $page->translations()->create([
                'locale' => 'hr', 'slug' => 'page-'.$id, 'title' => 'Uređeni naslov',
                'body_html' => '<p>Uređeni tekst stranice.</p>',
                ...NovemberFeedback::metadataDefaults($source),
            ]);
        }
        $service = app(LavaggioFeedbackUpdateService::class);
        $plan = $service->plan();
        foreach ($translations as $id => $translation) {
            $this->assertStringContainsString('samopos', mb_strtolower((string) $translation->meta_description));
        }
        $service->apply($plan);
        foreach ($translations as $id => $translation) {
            $translation->refresh();
            $expected = NovemberFeedback::metadataDefaults(NovemberFeedback::resource($sources[$id]));
            $this->assertSame($expected['meta_description'], $translation->meta_description);
            $this->assertSame($expected['excerpt'], $translation->excerpt);
            $this->assertStringNotContainsString('samopos', mb_strtolower((string) $translation->meta_description));
            $this->assertSame('<p>Uređeni tekst stranice.</p>', $translation->body_html);
            $this->assertSame('Uređeni naslov', $translation->title);
            $translation->update(['meta_description' => 'Moj prilagođeni opis samoposlužne iz povijesti.', 'excerpt' => 'Moj uređeni sažetak.']);
        }
        $this->assertSame([], $service->plan()['actions']);
        foreach ($translations as $translation) {
            $translation->refresh();
            $this->assertSame('Moj prilagođeni opis samoposlužne iz povijesti.', $translation->meta_description);
            $this->assertSame('Moj uređeni sažetak.', $translation->excerpt);
        }
    }

    public function test_command_refuses_nonlocal_destination(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('lavaggio:apply-november-feedback', ['--apply' => true])->assertFailed();
    }
}
