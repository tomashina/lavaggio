<?php

namespace App\Support\Lavaggio;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/** Present the editor's delivery headings as cards without storing or replacing its copy. */
class DeliveryPageSections
{
    public function fromHtml(string $html): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $encoded = mb_encode_numericentity('<div id="delivery-sections-root">'.$html.'</div>', [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
            if (! $document->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET)) {
                return null;
            }

            $root = (new DOMXPath($document))->query('//*[@id="delivery-sections-root"]')->item(0);
            $result = ['intro_html' => '', 'groups' => []];
            $group = null;
            $card = null;
            foreach ($root->childNodes as $node) {
                if ($node instanceof DOMElement && $node->tagName === 'h2') {
                    $result['groups'][] = ['title_html' => $this->innerHtml($document, $node), 'body_html' => '', 'cards' => []];
                    $group = array_key_last($result['groups']);
                    $card = null;
                } elseif ($node instanceof DOMElement && $node->tagName === 'h3') {
                    if ($group === null) {
                        $result['groups'][] = ['title_html' => '', 'body_html' => '', 'cards' => []];
                        $group = array_key_last($result['groups']);
                    }
                    $result['groups'][$group]['cards'][] = ['title_html' => $this->innerHtml($document, $node), 'body_html' => ''];
                    $card = array_key_last($result['groups'][$group]['cards']);
                } elseif ($card !== null) {
                    $result['groups'][$group]['cards'][$card]['body_html'] .= $document->saveHTML($node);
                } elseif ($group !== null) {
                    $result['groups'][$group]['body_html'] .= $document->saveHTML($node);
                } else {
                    $result['intro_html'] .= $document->saveHTML($node);
                }
            }

            return $result['groups'] === [] ? null : $result;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function innerHtml(DOMDocument $document, DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }
}
