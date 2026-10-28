<?php

namespace App\Support;

use DOMDocument;

class LavaggioArticleVideo
{
    /** @return array{id: string, layout: string, body_html: string, watch_url: string}|null */
    public static function extract(string $html): ?array
    {
        preg_match_all('~<iframe\b(?:"[^"]*"|\x27[^\x27]*\x27|[^\x27">])*>.*?</iframe\s*>~is', $html, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] ?? [] as [$markup, $offset]) {
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $document->loadHTML('<?xml encoding="UTF-8">'.$markup, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $iframe = $document->getElementsByTagName('iframe')->item(0);
            if ($iframe === null) {
                continue;
            }

            $source = parse_url($iframe->getAttribute('src'));
            if (! is_array($source) || ($source['scheme'] ?? '') !== 'https'
                || ! in_array(strtolower($source['host'] ?? ''), ['youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)
                || isset($source['user']) || isset($source['pass']) || isset($source['port'])
                || ! preg_match('~\A/embed/([A-Za-z0-9_-]{11})/?\z~D', $source['path'] ?? '', $video)) {
                continue;
            }

            $width = (int) $iframe->getAttribute('width');
            $height = (int) $iframe->getAttribute('height');

            return [
                'id' => $video[1],
                'layout' => $width > 0 && $height > 0 && $width >= $height ? 'landscape' : 'portrait',
                'body_html' => substr_replace($html, '', $offset, strlen($markup)),
                'watch_url' => 'https://www.youtube.com/watch?v='.$video[1],
            ];
        }

        return null;
    }
}
