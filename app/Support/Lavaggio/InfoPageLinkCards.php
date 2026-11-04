<?php

namespace App\Support\Lavaggio;

use DOMDocument;
use DOMElement;
use DOMXPath;

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
                        $cards[] = ['title' => $title, 'url' => PublicUrls::url($url)];
                    }
                }
            }

            return $cards;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
