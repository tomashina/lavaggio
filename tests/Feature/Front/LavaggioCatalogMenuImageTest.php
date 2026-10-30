<?php

namespace Tests\Feature\Front;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioCatalogMenuImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_category_panel_keeps_its_own_photo_and_categories_without_a_photo_stay_text_only(): void
    {
        $html = view('front.partials.lavaggio-catalog-nav', [
            'catalogItem' => [
                'label' => 'Web shop',
                'url' => '/shop',
                'children' => [
                    [
                        'label' => 'Deterdženti', 'url' => '/kategorija/deterdzenti',
                        'menu_image_url' => '/assets/lavaggio/deterdzenti.webp',
                        'menu_image_alt' => 'Njega bijelog rublja',
                        'children' => [['label' => 'Bijelo rublje', 'url' => '/kategorija/bijelo-rublje']],
                    ],
                    [
                        'label' => 'Uklanjanje mrlja', 'url' => '/kategorija/uklanjanje-mrlja',
                        'menu_image_url' => '/storage/navigation/categories/mrlje.webp',
                        'menu_image_alt' => '',
                        'children' => [['label' => 'Predtretman', 'url' => '/kategorija/predtretman']],
                    ],
                    ['label' => 'Pribor', 'url' => '/kategorija/pribor', 'children' => [['label' => 'Mrežice', 'url' => '/kategorija/mrezice']]],
                ],
            ],
        ])->render();

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $panels = $xpath->query('//*[@data-lavaggio-catalog-panel]');
        $this->assertCount(3, $panels);
        $this->assertFalse($panels->item(0)->hasAttribute('hidden'));
        $this->assertTrue($panels->item(1)->hasAttribute('hidden'));
        $this->assertTrue($panels->item(2)->hasAttribute('hidden'));

        $firstImage = $xpath->query('.//img', $panels->item(0))->item(0);
        $this->assertSame('/assets/lavaggio/deterdzenti.webp', $firstImage->getAttribute('src'));
        $this->assertSame('Njega bijelog rublja', $firstImage->getAttribute('alt'));
        $this->assertCount(1, $xpath->query('.//a[@href="/kategorija/bijelo-rublje"]', $panels->item(0)));
        $secondImage = $xpath->query('.//img', $panels->item(1))->item(0);
        $this->assertSame('/storage/navigation/categories/mrlje.webp', $secondImage->getAttribute('src'));
        $this->assertSame('Uklanjanje mrlja', $secondImage->getAttribute('alt'));
        $this->assertCount(0, $xpath->query('.//img', $panels->item(2)));
        $this->assertStringNotContainsString('has-image', $panels->item(2)->getAttribute('class'));
    }
}
