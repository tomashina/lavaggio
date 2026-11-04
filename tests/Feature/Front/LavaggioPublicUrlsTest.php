<?php

namespace Tests\Feature\Front;

use App\Models\Content\Page\InfoPage;
use App\Support\Lavaggio\PublicUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LavaggioPublicUrlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'app.locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_named_links_and_legacy_redirects_share_croatian_urls_and_preserve_query_strings(): void
    {
        $this->assertSame('/kontakt', route('contact.create', absolute: false));
        $this->assertSame('/trgovina', route('shop.index', absolute: false));
        $this->assertSame('/proizvod/deterdzent', route('products.show', 'deterdzent', false));
        $this->assertSame('/kategorija/pranje', route('categories.show', 'pranje', false));
        $this->assertSame('/moj-racun/narudzbe', route('account.orders', absolute: false));
        $this->assertSame('/prijava', route('front.auth.login', absolute: false));
        $this->assertSame('/naplata', route('checkout.create', absolute: false));
        $this->get('http://localhost/contact?tema=narudzba')->assertRedirect('/kontakt?tema=narudzba')->assertStatus(301);
        $this->get('http://localhost/page/cjenik-usluzno-pranje')->assertRedirect('/cjenik')->assertStatus(301);
        $this->get('http://localhost/stranica/cjenik')->assertRedirect('/cjenik')->assertStatus(301);
        $this->assertSame('/kontakt#podrska', PublicUrls::url('/contact#podrska'));
        $this->assertSame('https://example.org/contact', PublicUrls::url('https://example.org/contact'));
        $this->assertSame('#kontakt', PublicUrls::url('#kontakt'));
        $this->assertSame('Savjeti', PublicUrls::url('Savjeti'));
    }

    public function test_croatian_alias_renders_an_ordinary_page_and_uses_croatian_canonical(): void
    {
        $page = InfoPage::create(['code' => 'own-page', 'is_active' => true]);
        $page->translations()->create(['locale' => 'hr', 'slug' => 'o-nama', 'title' => 'O nama', 'body_html' => '<p>Uređeni sadržaj.</p>']);
        $response = $this->get('/stranica/o-nama');
        $response->assertOk()->assertSee('Uređeni sadržaj.')->assertSee(url('/stranica/o-nama'), false);
        $this->assertIsArray(app('router')->getRoutes()->compile());
        $this->get('/sitemap-pages-1.xml')->assertOk()->assertSee('/stranica/o-nama', false)->assertDontSee('/page/o-nama', false);
    }

    public function test_retired_service_pages_return_410_and_payment_callbacks_keep_their_routes(): void
    {
        foreach (['samoposluzna-praonica-rublja', 'kako-prati-rublje-kod-nas', 'kako-pripremiti-rublje-za-pranje', 'koji-program-za-pranje-odabrati', 'cjenik-samoposluzna-praonica', 'poklon-bon-lavaggio'] as $slug) {
            $this->get('/stranica/'.$slug)->assertStatus(410)->assertHeader('X-Robots-Tag', 'noindex');
            $this->get('/page/'.$slug)->assertStatus(410);
        }
        foreach (['checkout/wspay/return/ORDER-1', 'checkout/corvus/success', 'checkout/keks/success'] as $uri) {
            $this->assertSame('/'.$uri, PublicUrls::url('/'.$uri));
            $route = app('router')->getRoutes()->match(Request::create('/'.$uri, 'POST'));
            $this->assertFalse(str_starts_with((string) $route->getName(), 'lavaggio.localized.'));
        }
    }

    public function test_other_storefronts_keep_original_urls_and_croatian_aliases_are_unavailable(): void
    {
        config(['storefront.home' => 'watchline']);
        $this->assertSame('/contact', route('contact.create', absolute: false));
        $this->assertSame('/product/example', route('products.show', 'example', false));
        $this->get('/kontakt')->assertNotFound();
        $this->assertSame('/page/o-nama', PublicUrls::url('/page/o-nama'));
    }
}
