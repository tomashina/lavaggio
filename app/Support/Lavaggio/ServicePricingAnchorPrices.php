<?php

namespace App\Support\Lavaggio;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/** Add the approved, fixed 10 September baseline beside the editor's current service prices. */
class ServicePricingAnchorPrices
{
    public function render(string $html): string
    {
        if (trim($html) === '' || ! str_contains(strtolower($html), '<table')) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $encoded = mb_encode_numericentity('<div id="service-pricing-root">'.$html.'</div>', [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
            if (! $document->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET)) {
                return $html;
            }

            $xpath = new DOMXPath($document);
            $changed = false;
            foreach (iterator_to_array($xpath->query('//*[@id="service-pricing-root"]//table[not(ancestor::table)]')) as $table) {
                $header = $xpath->query('./thead/tr[1]', $table)->item(0);
                $headings = $header ? $this->cells($header) : [];
                if (count($headings) !== 2 || $this->normalize($headings[0]->textContent) !== 'usluga' || $this->normalize($headings[1]->textContent) !== 'cijena') {
                    continue;
                }

                $table->setAttribute('class', trim($table->getAttribute('class').' lavaggio-service-pricing'));
                $headings[1]->textContent = 'Trenutna cijena';
                $anchorHeading = $document->createElement('th');
                $anchorHeading->setAttribute('scope', 'col');
                $anchorHeading->appendChild($document->createTextNode('Sidrena cijena '));
                $date = $document->createElement('span', 'na dan 10.09.2026.');
                $date->setAttribute('class', 'lavaggio-service-pricing-date');
                $anchorHeading->appendChild($date);
                $header->appendChild($anchorHeading);

                $prices = $this->baseline();
                $service = '';
                foreach ($xpath->query('./tbody/tr', $table) as $row) {
                    $cells = $this->cells($row);
                    if (count($cells) !== 2) {
                        continue;
                    }
                    $label = trim($cells[0]->textContent);
                    $price = trim($cells[1]->textContent);
                    if ($label !== '') {
                        $service = $label;
                    }
                    if ($price === '') {
                        $row->setAttribute('class', trim($row->getAttribute('class').' lavaggio-service-pricing-category'));
                        $cells[0]->setAttribute('colspan', '3');
                        $row->removeChild($cells[1]);

                        continue;
                    }

                    $variant = str_contains($price, ':') ? trim(explode(':', $price, 2)[0]) : '';
                    $anchorPrice = $prices[$this->key($service, $variant)] ?? null;
                    if ($anchorPrice === null && ! preg_match('/€|\bEUR\b|%/u', $price)) {
                        // Coverage areas, minimum quantities and other notes are not prices.
                        $cells[1]->setAttribute('colspan', '2');

                        continue;
                    }

                    $cells[1]->setAttribute('class', trim($cells[1]->getAttribute('class').' lavaggio-service-pricing-current'));
                    $anchor = $document->createElement('td');
                    $anchor->setAttribute('class', 'lavaggio-service-pricing-anchor');
                    $anchor->appendChild($document->createTextNode($anchorPrice ?? '—'));
                    if ($anchorPrice === null) {
                        $anchor->setAttribute('title', 'Sidrena cijena za ovu uslugu nije evidentirana.');
                    }
                    $row->appendChild($anchor);
                }

                $scroll = $document->createElement('div');
                $scroll->setAttribute('class', 'lavaggio-service-pricing-scroll');
                $scroll->setAttribute('role', 'region');
                $scroll->setAttribute('aria-label', 'Cjenik usluga i sidrene cijene na dan 10.09.2026.');
                $scroll->setAttribute('tabindex', '0');
                $table->parentNode->replaceChild($scroll, $table);
                $scroll->appendChild($table);
                $changed = true;
            }

            if (! $changed) {
                return $html;
            }
            $rendered = '';
            foreach ($xpath->query('//*[@id="service-pricing-root"]')->item(0)->childNodes as $node) {
                $rendered .= $document->saveHTML($node);
            }

            return $rendered;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function cells(DOMElement $row): array
    {
        return array_values(array_filter(iterator_to_array($row->childNodes), fn ($node) => $node instanceof DOMElement && in_array($node->tagName, ['th', 'td'], true)));
    }

    private function baseline(): array
    {
        $snapshot = json_decode(file_get_contents(database_path('data/lavaggio-service-anchor-prices-20260910.json')), true, flags: JSON_THROW_ON_ERROR);
        $prices = [];
        foreach ($snapshot['rows'] as $row) {
            $prices[$this->key($row['service'], $row['variant'])] = $row['price'];
        }

        return $prices;
    }

    private function key(string $service, string $variant): string
    {
        return $this->normalize($service).'|'.$this->normalize($variant);
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($value))) ?? '');
    }
}
