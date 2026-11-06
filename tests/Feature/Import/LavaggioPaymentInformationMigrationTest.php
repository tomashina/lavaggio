<?php

namespace Tests\Feature\Import;

use App\Models\Content\Page\InfoPage;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioPaymentInformationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_information_preserves_editorial_copy_and_is_idempotent(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $settings = app(SystemSettingsService::class);
        $settings->put('store_footer_address', "Bareda d.o.o.\nBarban 70\n52207 Barban\nOIB: 96889385802\nIBAN: HR0000000000000000000\nUrednička napomena");
        $page = InfoPage::create(['code' => 'lavaggio-shop-webshop-nacini-placanja', 'is_active' => true]);
        $intro = '<h2>Naši uređeni uvjeti</h2><p>Uplata i dostava prema dogovoru.</p>';
        $translation = $page->translations()->create(['locale' => 'hr', 'slug' => 'webshop-nacini-placanja', 'title' => 'Uređeni naslov', 'body_html' => $intro.'<p>Plaćanje se obavlja tijekom završetka narudžbe putem sustava za online plaćanje.</p>']);
        $migration = require database_path('migrations/2026_11_06_090000_add_lavaggio_payment_information.php');
        $migration->up();
        $body = $translation->fresh()->body_html;
        $address = $settings->get('store_footer_address');
        $this->assertStringStartsWith($intro, $body);
        $this->assertStringContainsString('CorvusPay', $body);
        $this->assertStringContainsString('Visa, Mastercard, Maestro i Diners', $body);
        $this->assertStringContainsString('Apple Pay i Google Pay', $body);
        $this->assertSame('Uređeni naslov', $translation->fresh()->title);
        $this->assertSame("Bareda d.o.o.\nBarban 70\n52207 Barban\nOIB: 96889385802\nUrednička napomena\nIBAN: HR9423800061171000708", $address);
        $migration->up();
        $migration->down();
        $this->assertSame($body, $translation->fresh()->body_html);
        $this->assertSame($address, $settings->get('store_footer_address'));
    }

    public function test_other_storefronts_are_unchanged(): void
    {
        config(['storefront.home' => 'watchline']);
        $settings = app(SystemSettingsService::class);
        $settings->put('store_footer_address', 'Urednička adresa');
        $migration = require database_path('migrations/2026_11_06_090000_add_lavaggio_payment_information.php');
        $migration->up();
        $this->assertSame('Urednička adresa', $settings->get('store_footer_address'));
    }
}
