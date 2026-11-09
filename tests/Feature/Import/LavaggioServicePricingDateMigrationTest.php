<?php

namespace Tests\Feature\Import;

use App\Models\Content\Page\InfoPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioServicePricingDateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pricing_date_upgrade_preserves_edited_prices_titles_and_subsequent_changes(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $page = InfoPage::create(['code' => 'lavaggio-modx-88', 'is_active' => true]);
        $body = '<table><tbody><tr><td>UslugaCijena</td></tr><tr><td>Moja usluga</td><td>123,45 € / kg</td></tr></tbody></table><p>Cijene vrijede od: 01.06.2026</p><p>Urednička napomena.</p>';
        $translation = $page->translations()->create(['locale' => 'hr', 'slug' => 'cjenik-usluzno-pranje', 'title' => 'Moj uređeni naslov', 'body_html' => $body]);
        $migration = require database_path('migrations/2026_11_09_090000_restore_lavaggio_service_pricing_date.php');

        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertSame(str_replace('01.06.2026', '10.09.2026', $body), $translation->fresh()->body_html);
        $this->assertSame('Moj uređeni naslov', $translation->fresh()->title);
        $translation->update(['body_html' => '<p>Cijene vrijede od: 01.12.2026</p>']);
        $migration->up();
        $this->assertSame('<p>Cijene vrijede od: 01.12.2026</p>', $translation->fresh()->body_html);
    }

    public function test_other_storefronts_and_fresh_installations_are_unchanged(): void
    {
        $migration = require database_path('migrations/2026_11_09_090000_restore_lavaggio_service_pricing_date.php');
        config(['storefront.home' => 'lavaggio']);
        $migration->up();
        $this->assertDatabaseCount('content_info_pages', 0);

        $page = InfoPage::create(['code' => 'lavaggio-modx-88', 'is_active' => true]);
        $body = '<p>Cijene vrijede od: 01.06.2026</p>';
        $translation = $page->translations()->create(['locale' => 'hr', 'slug' => 'cjenik-usluzno-pranje', 'title' => 'Cjenik', 'body_html' => $body]);
        config(['storefront.home' => 'watchline']);
        $migration->up();
        $this->assertSame($body, $translation->fresh()->body_html);
    }
}
