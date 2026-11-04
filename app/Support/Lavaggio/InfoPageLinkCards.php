<?php

namespace App\Support\Lavaggio;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;

/** Turn navigation-only page bodies into cards; authored articles keep their original layout. */
class InfoPageLinkCards
{
    public function fromHtml(string $html): array
    {
        if (! PublicUrls::enabled() || trim($html) === '') {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $encoded = mb_encode_numericentity('<div id="page-links-root">'.$html.'</div>', [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
            if (! $document->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET)) {
                return [];
            }
            $xpath = new DOMXPath($document);
            $root = $xpath->query('//*[@id="page-links-root"]')->item(0);
            $cards = [];
            foreach ($root->childNodes as $list) {
                if (! $list instanceof DOMElement) {
                    if (trim($list->textContent) !== '') {
                        return [];
                    }

                    continue;
                }
                if (! in_array($list->tagName, ['ul', 'ol'], true)) {
                    return [];
                }
                foreach ($list->childNodes as $item) {
                    if (! $item instanceof DOMElement && trim($item->textContent) === '') {
                        continue;
                    }
                    if (! $item instanceof DOMElement || $item->tagName !== 'li') {
                        return [];
                    }
                    $links = $xpath->query('./a', $item);
                    $link = $links->item(0);
                    if ($links->length !== 1 || trim($item->textContent) !== trim($link->textContent) || $xpath->query('.//img|.//ul|.//ol', $item)->length > 0) {
                        return [];
                    }
                    $url = trim($link->getAttribute('href'));
                    $title = trim($link->textContent);
                    if ($title === '' || ! preg_match('~^(?:/(?!/)|https?://)~i', $url)) {
                        return [];
                    }
                    if (! PublicUrls::retiredSlug((string) parse_url($url, PHP_URL_PATH))) {
                        $cards[] = ['title' => $title, 'url' => PublicUrls::url($url), 'icon' => $this->icon($url, $title)];
                    }
                }
            }

            return $cards;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function icon(string $url, string $title): string
    {
        $subject = Str::lower(Str::ascii((string) parse_url($url, PHP_URL_PATH).' '.$title));

        return match (true) {
            str_contains($subject, 'radno') => 'clock',
            str_contains($subject, 'recenz'), str_contains($subject, 'iskustv') => 'star',
            str_contains($subject, 'kucni'), str_contains($subject, 'pravila') => 'list-check',
            str_contains($subject, 'zaposlj') => 'user-plus',
            str_contains($subject, 'zastit'), str_contains($subject, 'privatnost'), str_contains($subject, 'prigovor') => 'shield-halved',
            str_contains($subject, 'tko-smo'), str_contains($subject, 'tko smo'), str_contains($subject, 'o-nama') => 'user',
            str_contains($subject, 'dostav'), str_contains($subject, 'prikup') => 'truck',
            str_contains($subject, 'kontakt') => 'comments',
            default => 'file-lines',
        };
    }
}
