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

    public function test_delivery_cards_preserve_editor_copy_links_and_heading_order_without_changing_stored_content(): void
    {
        config(['storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'app.locale' => 'hr']);
        app()->setLocale('hr');
        $page = InfoPage::create(['code' => 'lavaggio-shop-dostava', 'is_active' => true]);
        $body = '<p>Uvod u dostavu.</p><h3>Besplatna dostava od 70 €</h3><p>Za pošiljke do <strong>20 kg</strong>.</p>'
            .'<h2>Načini dostave</h2><p>Odaberite način.</p><h3>Osobno preuzimanje</h3><p>Barban 70.</p>'
            .'<h3>GLS paketomat</h3><p>Cijena: 4,90 €.</p><h2>Trebate pomoć?</h2><p><a href="/kontakt">Kontaktirajte nas</a>.</p>';
        $translation = $page->translations()->create(['locale' => 'hr', 'slug' => 'dostava', 'title' => 'Dostava', 'body_html' => $body]);

        $response = $this->get(route('pages.show', 'dostava'))->assertOk()
            ->assertSee('lavaggio-info-page--delivery')
            ->assertSeeInOrder(['Uvod u dostavu.', 'Besplatna dostava od 70 €', 'Načini dostave', 'Odaberite način.', 'Osobno preuzimanje', 'GLS paketomat', 'Trebate pomoć?'])
            ->assertSee('href="/kontakt"', false);
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(3, $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " lavaggio-delivery-card ")]')->length);
        $this->assertSame($body, $translation->fresh()->body_html);

        $translation->update(['body_html' => '<p>Sadržaj u pripremi.</p>']);
        $this->get(route('pages.show', 'dostava'))->assertOk()
            ->assertSee('Sadržaj u pripremi.')->assertDontSee('class="lavaggio-delivery-card"', false);
        config(['storefront.home' => 'watchline']);
        $translation->update(['body_html' => $body]);
        $this->get(route('pages.show', 'dostava'))->assertOk()
            ->assertDontSee('class="lavaggio-delivery-card"', false)->assertSee($body, false);
    }
}
