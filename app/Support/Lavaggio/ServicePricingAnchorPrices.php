<?php

namespace App\Support\Lavaggio;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/** Add the approved, fixed 10 September baseline beside the editor's current service prices. */
class ServicePricingAnchorPrices
{
    public function render(string $html, bool $selfService = false): string
    {
        return $this->present($html, $selfService)['html'];
    }

    public function present(string $html, bool $selfService = false): array
    {
        $rows = [];
        if (trim($html) === '' || ! str_contains(strtolower($html), '<table')) {
            return ['html' => $html, 'rows' => $rows];
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $encoded = mb_encode_numericentity('<div id="service-pricing-root">'.$html.'</div>', [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
            if (! $document->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET)) {
                return ['html' => $html, 'rows' => $rows];
            }

            $xpath = new DOMXPath($document);
            $changed = false;
            foreach (iterator_to_array($xpath->query('//*[@id="service-pricing-root"]//table[not(ancestor::table)]')) as $table) {
                $header = $this->header($document, $xpath, $table);
                $headings = $header ? $this->cells($header) : [];
                if (count($headings) !== 2 || $this->normalize($headings[0]->textContent) !== 'usluga' || $this->normalize($headings[1]->textContent) !== 'cijena') {
                    continue;
                }

                $table->setAttribute('class', trim($table->getAttribute('class').' lavaggio-service-pricing'));
                $headings[1]->textContent = 'Trenutna cijena';
                $anchorHeading = $document->createElement('th');
                $anchorHeading->setAttribute('scope', 'col');
                $anchorHeading->appendChild($document->createTextNode('Bazna cijena '));
                $date = $document->createElement('span', 'na dan 10.09.2026.');
                $date->setAttribute('class', 'lavaggio-service-pricing-date');
                $anchorHeading->appendChild($date);
                $header->appendChild($anchorHeading);

                $prices = $this->baseline();
                $service = '';
                foreach ($xpath->query('./tbody/tr|./tr', $table) as $row) {
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
                    if ($selfService) {
                        // FIRA Barban, codes 104–107, reference date 10 September 2026.
                        $weight = preg_match('/^(\d+)\s*kg/u', $price, $match) ? $match[1].' kg' : '';
                        $extra = str_contains($price, 'dodatni žeton') && str_contains($price, '10 minuta');
                        $variant = $extra ? 'dodatnih 10 minuta' : $weight;
                        $anchorPrice = match ($this->key($service, $variant)) {
                            'pranje|10 kg' => '6,00 €', 'pranje|15 kg' => '9,00 €',
                            'susenje|14 kg' => '4,00 €', 'susenje|dodatnih 10 minuta' => '1,00 €',
                            default => null,
                        };
                        if ($extra && ! str_contains($price, '€')) {
                            $price .= ' (1,00 €)';
                            $cells[1]->appendChild($document->createTextNode(' (1,00 €)'));
                        }
                    }
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
                        $anchor->setAttribute('title', 'Bazna cijena za ovu uslugu nije evidentirana.');
                    }
                    $name = $service === 'Van tih uvjeta' ? 'Prikup i dostava izvan navedenih područja ili uvjeta' : $service;
                    $name .= $variant === '' ? '' : ' – '.$variant;
                    $sale = preg_match('/\b(akcija|akcijska|sniženo|snižena|popust)\b/ui', $price) === 1;
                    $rows[] = ['name' => $name, 'price' => $price, 'sale' => $sale, 'base' => $anchorPrice];
                    $row->appendChild($anchor);
                }

                $scroll = $document->createElement('div');
                $scroll->setAttribute('class', 'lavaggio-service-pricing-scroll');
                $scroll->setAttribute('role', 'region');
                $scroll->setAttribute('aria-label', 'Cjenik usluga i bazne cijene na dan 10.09.2026.');
                $scroll->setAttribute('tabindex', '0');
                $table->parentNode->replaceChild($scroll, $table);
                $scroll->appendChild($table);
                $changed = true;
            }

            if (! $changed) {
                return ['html' => $html, 'rows' => $rows];
            }
            $rendered = '';
            foreach ($xpath->query('//*[@id="service-pricing-root"]')->item(0)->childNodes as $node) {
                $rendered .= $document->saveHTML($node);
            }

            return ['html' => $rendered, 'rows' => $rows];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function header(DOMDocument $document, DOMXPath $xpath, DOMElement $table): ?DOMElement
    {
        $header = $xpath->query('./thead/tr[1]', $table)->item(0);
        if ($header instanceof DOMElement) {
            return $header;
        }

        // Quill saves pasted table headings as ordinary cells, sometimes merging both labels.
        $firstRow = $xpath->query('./tbody/tr[1]|./tr[1]', $table)->item(0);
        $cells = $firstRow instanceof DOMElement ? $this->cells($firstRow) : [];
        $isMergedHeading = count($cells) === 1 && str_replace(' ', '', $this->normalize($cells[0]->textContent)) === 'uslugacijena';
        $isTwoCellHeading = count($cells) === 2 && $this->normalize($cells[0]->textContent) === 'usluga' && $this->normalize($cells[1]->textContent) === 'cijena';
        if (! $isMergedHeading && ! $isTwoCellHeading) {
            return null;
        }

        $thead = $document->createElement('thead');
        $header = $document->createElement('tr');
        foreach (['Usluga', 'Cijena'] as $label) {
            $heading = $document->createElement('th', $label);
            $heading->setAttribute('scope', 'col');
            $header->appendChild($heading);
        }
        $thead->appendChild($header);
        $table->insertBefore($thead, $table->firstChild);
        $firstRow->parentNode->removeChild($firstRow);

        return $header;
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
