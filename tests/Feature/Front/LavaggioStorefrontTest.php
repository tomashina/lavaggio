<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Services\Front\StoreSettingsService;
use Database\Seeders\LavaggioStorefrontSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.name' => 'Lavaggio',
            'app.locale' => 'hr',
            'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia',
        ]);
        app()->setLocale('hr');
        $this->seed(LavaggioStorefrontSeeder::class);
    }

    public function test_empty_store_renders_lavaggio_without_inherited_catalog_or_branding(): void
    {
        $this->get(route('home'))->assertOk()
            ->assertSee('Njega rublja s potpisom Lavaggio.')
            ->assertSee('Ponuda je u pripremi.')
            ->assertSee('lavaggio-storefront')
            ->assertSee('front-theme/styles/lavaggio.css')
            ->assertSee('assets/lavaggio/favicon.svg')
            ->assertDontSee('storage/assets/lavaggio/')
            ->assertDontSee('watchline-logo.svg')
            ->assertDontSee('watchline-favicon')
            ->assertDontSee('CLUSE')
            ->assertDontSee('Watchline')
            ->assertDontSee('WSPay');

        foreach (['products', 'categories', 'catalog_manufacturers', 'orders'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->get(route('shop.index'))->assertOk();
        $this->get(route('front.auth.login'))->assertOk();
    }

    public function test_brand_assets_use_the_public_asset_directory_on_storefront_and_staff_login(): void
    {
        $branding = app(StoreSettingsService::class)->branding();
        $this->assertSame(asset('assets/lavaggio/images/logo/lavaggio-logo-dark.svg'), $branding['logo_url']);
        $this->assertSame(asset('assets/lavaggio/images/logo/lavaggio-logo-light.svg'), $branding['dark_logo_url']);

        $this->get(route('login'))->assertOk()
            ->assertSee('lavaggio-auth')
            ->assertSee('front-theme/styles/lavaggio-auth.css')
            ->assertSee('assets/lavaggio/images/logo/lavaggio-logo-dark.svg')
            ->assertSee('assets/lavaggio/fonts/Satoshi-Variable.woff2')
            ->assertDontSee('storage/assets/lavaggio/')
            ->assertDontSee('watchline-logo.svg')
            ->assertDontSee('watchline-favicon');
    }

    public function test_admin_categories_render_in_the_existing_desktop_category_row(): void
    {
        $category = Category::create([
            'scope' => 'catalog', 'code' => 'njega', 'is_active' => true, 'show_in_menu' => true,
        ]);
        $category->translations()->create([
            'scope' => 'catalog', 'locale' => 'hr', 'name' => 'Njega', 'slug' => 'njega',
        ]);

        $response = $this->get(route('home'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//nav[contains(@class,"watchline-desktop-categories")]//a[@data-watchline-category-link]');
        $this->assertSame(1, $links->length);
        $this->assertSame('Njega', $links->item(0)->textContent);
        $this->assertSame(route('categories.show', 'njega'), $links->item(0)->getAttribute('href'));

        $category->update(['show_in_menu' => false]);
        $this->get(route('home'))->assertOk()
            ->assertDontSee(route('categories.show', 'njega'));
    }
}
