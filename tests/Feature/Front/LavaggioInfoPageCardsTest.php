<?php

namespace Tests\Feature\Front;

use App\Models\Content\Page\InfoPage;
use App\Support\Lavaggio\InfoPageLinkCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioInfoPageCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_pages_use_cards_and_croatian_links_while_articles_keep_lists(): void
    {
        config(['storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'app.locale' => 'hr']);
        app()->setLocale('hr');
        $page = InfoPage::create(['code' => 'nav-page', 'is_active' => true]);
        $translation = $page->translations()->create([
            'locale' => 'hr', 'slug' => 'o-praonici', 'title' => 'O praonici',
            'body_html' => '<ul><li><a href="/page/tko-smo-mi">Tko smo mi?</a></li><li><a href="/contact">Kontakt</a></li></ul>',
        ]);
        $this->get(route('pages.show', 'o-praonici'))->assertOk()
            ->assertSee('lavaggio-page-link-cards')
            ->assertSee('lavaggio-info-page--links')
            ->assertSee('href="/stranica/tko-smo-mi"', false)->assertSee('href="/kontakt"', false);
        $cards = app(InfoPageLinkCards::class)->fromHtml($translation->body_html);
        $this->assertSame(['Tko smo mi?', 'Kontakt'], array_column($cards, 'title'));
        $this->assertSame(['user', 'comments'], array_column($cards, 'icon'));
        $translation->update(['body_html' => '<p>Upute za njegu rublja.</p><ul><li><a href="/contact">Kontakt</a></li></ul>']);
        $this->get(route('pages.show', 'o-praonici'))->assertOk()
            ->assertDontSee('class="lavaggio-page-link-cards"', false)->assertSee('Upute za njegu rublja.');
        config(['storefront.home' => 'watchline']);
        $this->assertSame([], app(InfoPageLinkCards::class)->fromHtml('<ul><li><a href="/contact">Kontakt</a></li></ul>'));
    }
}
