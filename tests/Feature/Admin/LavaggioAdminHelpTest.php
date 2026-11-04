<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioAdminHelpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'app.locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_lavaggio_manual_matches_the_current_editor_workflows_and_omits_unused_modules(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        foreach (['catalog_use_blog', 'catalog_use_options', 'catalog_use_actions', 'catalog_use_api'] as $feature) {
            app(SystemSettingsService::class)->put($feature, true, 'bool');
        }

        $this->actingAs($admin)->get(route('admin.help.index'))->assertOk()
            ->assertSeeText('Lavaggio administracija')
            ->assertSeeText('do 12 promo slajdova')
            ->assertSeeText('čuva njegov sadržaj kao prvi slajd')
            ->assertSeeText('Kartice prema problemu')
            ->assertSeeText('Kolekcije artikala')
            ->assertSeeText('Teme pitanja i odgovora')
            ->assertSeeText('Kartice na kontakt stranici')
            ->assertDontSeeText('Glavni banner koristi jednu zajedničku sliku i jedan opis slike.')
            ->assertSeeText('unutar artikla otvorite karticu Opcije')
            ->assertSeeText('Stavke izbornika')
            ->assertSeeText('Novi korisnik')
            ->assertSeeText('putanja nadređenih stranica')
            ->assertSee('data-manual-entry="bundle-ponude"', false)
            ->assertSee('data-manual-entry="statistike-kupaca"', false)
            ->assertDontSee('data-manual-section="integracije"', false)
            ->assertDontSee('data-manual-entry="b2b-cjenici"', false)
            ->assertDontSee('data-manual-entry="wholesale-api"', false)
            ->assertDontSeeText('EPREL')
            ->assertDontSeeText('B2B')
            ->assertDontSeeText('Herrera administracija');

        $this->get(route('admin.bundles'))->assertOk()
            ->assertSee('href="'.route('admin.help.index').'#bundle-ponude"', false);
        $this->get(route('admin.settings.local.resource', ['resource' => 'order-statuses']))->assertOk()
            ->assertSee('href="'.route('admin.help.index').'#statusi-narudzbi"', false)
            ->assertSee('Statusi narudžbi')
            ->assertDontSee('Unesite kod jezika, locale', false);
    }

    public function test_lavaggio_manual_filters_topics_counts_and_links_by_permissions_and_features(): void
    {
        $operator = $this->operator(['content.navigation.view', 'content.blog.view']);
        app(SystemSettingsService::class)->put('catalog_use_blog', false, 'bool');

        $this->actingAs($operator)->get(route('admin.help.index'))->assertOk()
            ->assertSee('data-manual-entry-count="1"', false)
            ->assertSee('data-manual-entry="navigacija"', false)
            ->assertSee('data-manual-toc-topic="navigacija"', false)
            ->assertSee('href="'.route('admin.content.navigation').'"', false)
            ->assertDontSee('data-manual-entry="blog"', false)
            ->assertDontSee('data-manual-entry="artikli"', false)
            ->assertDontSee('data-manual-section="katalog"', false)
            ->assertDontSee('data-manual-toc-topic="blog"', false);
    }

    public function test_local_settings_help_supports_granular_access_and_all_profile_links_are_registered(): void
    {
        $operator = $this->operator(['settings.languages.manage']);
        $this->actingAs($operator)->get(route('admin.help.index'))->assertOk()
            ->assertSee('data-manual-entry-count="1"', false)
            ->assertSee('data-manual-entry="jezici"', false)
            ->assertSee('href="'.route('admin.settings.local.resource', ['resource' => 'languages']).'"', false)
            ->assertDontSee('data-manual-entry="statusi-narudzbi"', false);

        $ids = [];
        foreach (config('admin_manual_lavaggio.sections') as $section) {
            foreach ($section['items'] as $item) {
                $this->assertTrue(Route::has($item['route']), $item['id']);
                $this->assertIsString(route($item['route'], $item['route_parameters'] ?? []));
                $ids[] = $item['id'];
            }
        }
        $this->assertCount(count($ids), array_unique($ids));
    }

    private function operator(array $abilities): User
    {
        $operator = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        Bouncer::allow($operator)->to(['admin.access', ...$abilities]);

        return $operator;
    }
}
