<?php

namespace Tests\Feature\Front;

use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Faq;
use Database\Seeders\LavaggioStorefrontSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioFaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.locale' => 'hr',
            'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera',
        ]);
        app()->setLocale('hr');
        $this->seed(LavaggioStorefrontSeeder::class);
    }

    public function test_all_active_source_questions_and_answer_html_remain_in_order_in_initially_closed_native_accordions(): void
    {
        $this->sourcePage();
        $faqs = $this->sourceQuestions();
        $inactive = Faq::create(['code' => 'inactive-faq', 'is_active' => false, 'sort_order' => -1]);
        $inactive->translations()->create([
            'locale' => 'hr', 'slug' => 'neaktivno-pitanje',
            'question' => 'Neaktivno pitanje ne smije biti prikazano?',
            'answer_html' => '<p>Neaktivan odgovor.</p>',
        ]);

        $response = $this->get(route('faq.index'))->assertOk()
            ->assertSee('front-theme/styles/lavaggio-faq.css')
            ->assertDontSee('Neaktivno pitanje ne smije biti prikazano?')
            ->assertDontSee('Neaktivan odgovor.');
        $xpath = $this->xpath($response->getContent());
        $items = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-faq-list ")]/details[@data-lavaggio-faq-item]');

        $this->assertSame(13, $items->length);
        foreach ($faqs as $index => $faq) {
            $item = $items->item($index);
            $this->assertFalse($item->hasAttribute('open'));
            $this->assertSame(1, $xpath->query('./summary', $item)->length);
            $this->assertStringContainsString($faq['question'], $xpath->query('./summary', $item)->item(0)->textContent);
            $this->assertStringContainsString('faq-accordion-item', $item->getAttribute('class'));
            $this->assertSame(1, $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-faq-answer ")]', $item)->length);
            $response->assertSee($faq['answer_html'], false);
        }
    }

    public function test_imported_title_and_metadata_are_kept_without_subtitle_and_page_content_follows_the_questions(): void
    {
        $this->sourcePage();
        $faqs = $this->sourceQuestions();

        $response = $this->get(route('faq.index'))->assertOk()
            ->assertDontSee('Uvezeni sažetak koji se ne prikazuje uz naslov.')
            ->assertDontSee(__('ui.faq.subtitle'))
            ->assertSee('<p>Još imate pitanja? <a href="/contact">Javite nam se</a>.</p>', false);
        $html = $response->getContent();
        $xpath = $this->xpath($html);
        $heading = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-faq-page ")]//h1');

        $this->assertSame(1, $heading->length);
        $this->assertSame('Pitanja i odgovori iz praonice', trim($heading->item(0)->textContent));
        $this->assertSame('Pitanja i odgovori | Lavaggio izvorni SEO', trim($xpath->query('//title')->item(0)->textContent));
        $this->assertSame('Izvorni SEO opis pitanja i odgovora praonice.', $xpath->query('//meta[@name="description"]')->item(0)->getAttribute('content'));
        $this->assertSame(route('faq.index'), $xpath->query('//link[@rel="canonical"]')->item(0)->getAttribute('href'));
        $this->assertGreaterThan(strpos($html, $faqs[12]['answer_html']), strpos($html, 'Još imate pitanja?'));
    }

    public function test_lavaggio_without_imported_page_uses_the_translated_title_without_the_generic_subtitle(): void
    {
        $response = $this->get(route('faq.index'))->assertOk()
            ->assertSee(__('ui.faq.title'))
            ->assertSee(__('ui.faq.empty'))
            ->assertSee('lavaggio-faq-page');
        $page = $this->xpath($response->getContent())->query('//*[contains(concat(" ", normalize-space(@class), " "), " lavaggio-faq-page ")]')->item(0);

        $this->assertStringNotContainsString(__('ui.faq.subtitle'), $page->textContent);
    }

    public function test_other_storefront_keeps_its_existing_faq_layout_and_open_first_answer(): void
    {
        $this->sourcePage();
        $faqs = $this->sourceQuestions();
        config()->set('storefront.home', 'watchline');

        $response = $this->get(route('faq.index'))->assertOk()
            ->assertSee(__('ui.faq.subtitle'))
            ->assertDontSee('Pitanja i odgovori iz praonice')
            ->assertDontSee('lavaggio-faq-page')
            ->assertDontSee('front-theme/styles/lavaggio-faq.css');
        $items = $this->xpath($response->getContent())->query('//details[contains(concat(" ", normalize-space(@class), " "), " faq-accordion-item ")]');

        $this->assertSame(13, $items->length);
        $this->assertTrue($items->item(0)->hasAttribute('open'));
        $this->assertFalse($items->item(1)->hasAttribute('open'));
        $response->assertSee($faqs[0]['answer_html'], false);
    }

    private function sourcePage(): void
    {
        $page = InfoPage::create(['code' => 'lavaggio-modx-72', 'is_active' => true]);
        $page->translations()->create([
            'locale' => 'hr',
            'slug' => 'pitanja-i-odgovori',
            'title' => 'Pitanja i odgovori iz praonice',
            'excerpt' => 'Uvezeni sažetak koji se ne prikazuje uz naslov.',
            'body_html' => '<p>Još imate pitanja? <a href="/contact">Javite nam se</a>.</p>',
            'meta_title' => 'Pitanja i odgovori | Lavaggio izvorni SEO',
            'meta_description' => 'Izvorni SEO opis pitanja i odgovora praonice.',
        ]);
    }

    private function sourceQuestions(): array
    {
        $questions = [
            'Gdje se nalazi vaša praonica?',
            'Da li radite prikup i dostavu rublja?',
            'Da li mogu sam/a oprati svoje rublje?',
            'Da li mogu ostaviti svoje rublje da mi ga vi operete?',
            'Radite li i nedjeljom? Kada ste otvoreni?',
            'Kolika je cijena za pranje rublja?',
            'Da li koristite omekšivač?',
            'Trebam li donijeti vlastiti deterdžent?',
            'Kako mogu platiti?',
            'Mogu li oprati deku ili poplun?',
            'Koje su veličine mašina za pranje i sušenje rublja?',
            'Što znači OPRANO - OSUŠENO - SLOŽENO rublje?',
            'Što znači OPRANO - OSUŠENO - OPEGLANO rublje?',
        ];
        $faqs = [];
        foreach ($questions as $index => $question) {
            $faqs[] = [
                'question' => $question,
                'answer_html' => '<p>Izvorni odgovor '.($index + 1).' s <strong>važnim detaljem</strong> i <a href="/page/usluge">poveznicom na uslugu</a>.</p><ul><li>Uputa za rublje '.($index + 1).'.</li></ul>',
            ];
        }

        foreach (array_reverse($faqs, true) as $index => $translation) {
            $faq = Faq::create([
                'code' => 'lavaggio-modx-faq-'.($index + 1),
                'group_code' => 'lavaggio',
                'is_active' => true,
                'is_featured' => false,
                'sort_order' => $index,
            ]);
            $faq->translations()->create($translation + ['locale' => 'hr', 'slug' => 'pitanje-'.($index + 1)]);
        }

        return $faqs;
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

        return new \DOMXPath($document);
    }
}
