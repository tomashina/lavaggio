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

    public function test_catalog_categories_render_under_web_shop_on_desktop_and_mobile(): void
    {
        $category = Category::create([
            'scope' => 'catalog', 'code' => 'njega', 'is_active' => true, 'show_in_menu' => true,
        ]);
        $category->translations()->create([
            'scope' => 'catalog', 'locale' => 'hr', 'name' => 'Njega', 'slug' => 'njega',
        ]);
        $child = Category::create([
            'scope' => 'catalog', 'code' => 'omeksivaci', 'parent_id' => $category->id,
            'is_active' => true, 'show_in_menu' => true,
        ]);
        $child->translations()->create([
            'scope' => 'catalog', 'locale' => 'hr', 'name' => 'Omekšivači', 'slug' => 'omeksivaci',
        ]);

        $response = $this->get(route('home'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $triggers = $xpath->query('//nav[contains(@class,"lavaggio-header-navigation")]//button[@data-lavaggio-catalog-trigger]');
        $this->assertSame(1, $triggers->length);
        $this->assertStringContainsString('Web shop', $triggers->item(0)->textContent);
        $this->assertSame('false', $triggers->item(0)->getAttribute('aria-expanded'));
        $links = $xpath->query('//*[@data-lavaggio-catalog-menu]//a[@data-lavaggio-catalog-root]');
        $this->assertSame(1, $links->length);
        $this->assertSame('Njega', trim($links->item(0)->textContent));
        $this->assertSame(route('categories.show', 'njega'), $links->item(0)->getAttribute('href'));
        $this->assertSame(1, $xpath->query('//*[@data-lavaggio-catalog-panel]//a[@href="'.route('categories.show', 'omeksivaci').'"]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-lavaggio-catalog-menu]//img')->length);
        $mobileCatalog = '//*[@data-mobile-menu-panel]//details[@data-mobile-menu-catalog]';
        $this->assertSame(1, $xpath->query($mobileCatalog)->length);
        $this->assertStringContainsString('Web shop', $xpath->query($mobileCatalog.'/summary')->item(0)->textContent);
        $this->assertSame(1, $xpath->query($mobileCatalog.'//a[@href="'.route('categories.show', 'njega').'"]')->length);
        $this->assertSame(1, $xpath->query($mobileCatalog.'//a[@href="'.route('categories.show', 'omeksivaci').'"]')->length);

        $category->update(['show_in_menu' => false]);
        $this->get(route('home'))->assertOk()
            ->assertDontSee(route('categories.show', 'njega'));
    }
}
