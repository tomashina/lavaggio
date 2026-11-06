<?php

namespace Tests\Feature\Front;

use App\Models\Content\Page\InfoPage;
use App\Support\Lavaggio\ServicePricingAnchorPrices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioServicePricingAnchorPricesTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_approved_price_list_has_fixed_anchor_prices_while_notes_and_non_price_rows_are_preserved(): void
    {
        $source = json_decode(file_get_contents(database_path('data/lavaggio-content-20261028.json')), true, flags: JSON_THROW_ON_ERROR);
        $html = collect($source['resources'])->firstWhere('id', 88)['body_html'];
        $presenter = app(ServicePricingAnchorPrices::class);
        $rendered = $presenter->render($html);
        $xpath = $this->xpath($rendered);

        $this->assertSame(24, $xpath->query('//td[@class="lavaggio-service-pricing-anchor"]')->length);
        $this->assertSame(3, $xpath->query('//thead/tr/th')->length);
        $this->assertStringContainsString('Sidrena cijena', $rendered);
        $this->assertStringContainsString('na dan 10.09.2026.', $rendered);
        foreach ($xpath->query('//td[@class="lavaggio-service-pricing-anchor"]') as $cell) {
            $this->assertSame(trim($cell->previousSibling->textContent), trim($cell->textContent));
        }
        $this->assertSame(2, (int) $xpath->query('//tr[td[contains(., "Minimalne količine")]]/td[2]')->item(0)->getAttribute('colspan'));
        $this->assertStringContainsString('0,80 € / km', $rendered);
        $this->assertStringContainsString('svaka narudžba pere odvojeno', $rendered);
        $this->assertStringContainsString('href="[[~77]]"', $rendered);
        $this->assertSame($rendered, $presenter->render($rendered));
    }

    public function test_admin_price_edits_and_reordered_rows_keep_the_reference_amount_without_changing_stored_content(): void
    {
        config(['storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'app.locale' => 'hr']);
        app()->setLocale('hr');
        $body = '<table><thead><tr><th>Usluga</th><th>Cijena</th></tr></thead><tbody>'
            .'<tr><td>Košulja - dugi rukavi</td><td><strong>5,00€ / kom</strong></td></tr>'
            .'<tr><td>DEKA / POPLUN * - skidanje fleka, pranje, dezinfekcija ozonom, sušenje</td><td>Mali format: 19,00 € / kom</td></tr>'
            .'<tr><td></td><td>Srednji format: 21,00 € / kom</td></tr>'
            .'<tr><td>Nova usluga</td><td>7,00 € / kg</td></tr>'
            .'</tbody></table><p>Moja nova napomena.</p>';
        $page = InfoPage::create(['code' => 'lavaggio-modx-88', 'is_active' => true]);
        $translation = $page->translations()->create(['locale' => 'hr', 'slug' => 'cjenik-usluzno-pranje', 'title' => 'Cjenik', 'body_html' => $body]);

        $response = $this->get('/cjenik')->assertOk()->assertSee('lavaggio-service-pricing.css')
            ->assertSee('Moja nova napomena.')->assertSee('<strong>5,00€ / kom</strong>', false);
        $xpath = $this->xpath($response->getContent());
        $anchors = $xpath->query('//td[@class="lavaggio-service-pricing-anchor"]');
        $this->assertSame(['4,00€ / kom', 'Mali format: 15,00 € / kom', 'Srednji format: 17,00 € / kom', '—'], array_map(fn ($cell) => trim($cell->textContent), iterator_to_array($anchors)));
        $this->assertSame($body, $translation->fresh()->body_html);

        config(['storefront.home' => 'watchline']);
        $this->get(route('pages.show', 'cjenik-usluzno-pranje'))->assertOk()
            ->assertDontSee('lavaggio-service-pricing.css')->assertSee($body, false);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($document);
    }
}
