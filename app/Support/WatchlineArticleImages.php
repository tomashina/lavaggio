<?php

namespace App\Support;

use App\Services\Import\HerreraLegacyUrlService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;

/** Enrich image attributes at render time, using existing captions and explicit product links only. */
class WatchlineArticleImages
{
    public function render(string $html, bool $hasLeadingCover = false): string
    {
        if (config('storefront.home') !== 'watchline' || ! str_contains(strtolower($html), '<img')) {
            return $html;
        }
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $encoded = mb_encode_numericentity('<div data-article-image-root="1">'.$html.'</div>', [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
            if (! $document->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET)) {
                return $html;
            }
            $xpath = new DOMXPath($document);
            $images = iterator_to_array($xpath->query('//img'));
            $pattern = '~<img\b(?:"[^"]*"|\x27[^\x27]*\x27|[^\x27">])*>~i';
            preg_match_all($pattern, $html, $originals);
            if (count($originals[0]) !== count($images)) {
                return $html;
            }
            $contexts = [];
            foreach ($images as $index => $image) {
                if (! $image->hasAttribute('alt')) {
                    $contexts[$index] = $this->context($image, $xpath);
                }
            }
            $productNames = $this->productNames(array_column(array_filter($contexts, fn ($context) => $context['text'] === ''), 'url'));
            $replacements = [];
            foreach ($images as $index => $image) {
                if (isset($contexts[$index])) {
                    $context = $contexts[$index];
                    $alt = $context['text'] ?: ($productNames[$context['url']] ?? '');
                    if ($alt !== '') {
                        $image->setAttribute('alt', $alt);
                    }
                }
                if (! $image->hasAttribute('loading') && ($hasLeadingCover || $index > 0)) {
                    $image->setAttribute('loading', 'lazy');
                }
                if (! $image->hasAttribute('decoding')) {
                    $image->setAttribute('decoding', 'async');
                }
                if (! $image->hasAttribute('width') && ! $image->hasAttribute('height')) {
                    $size = $this->localDimensions($image->getAttribute('src'));
                    if ($size) {
                        $image->setAttribute('width', (string) $size[0]);
                        $image->setAttribute('height', (string) $size[1]);
                    }
                }
                $replacements[] = $document->saveHTML($image);
            }
            $index = 0;

            // Replace only img tags: article text, links, Word markup and authored layout remain byte-for-byte intact.
            return preg_replace_callback($pattern, function () use (&$index, $replacements): string {
                return $replacements[$index++];
            }, $html) ?? $html;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function context(DOMElement $image, DOMXPath $xpath): array
    {
        foreach (['title', 'aria-label'] as $attribute) {
            if ($text = $this->label($image->getAttribute($attribute))) {
                return ['text' => $text, 'url' => ''];
            }
        }
        $figure = $xpath->query('ancestor::figure[1]', $image)->item(0);
        if ($figure instanceof DOMElement && $figure->getElementsByTagName('img')->length === 1) {
            $caption = $figure->getElementsByTagName('figcaption')->item(0);
            if ($caption && ($text = $this->label($caption->textContent))) {
                return ['text' => $text, 'url' => ''];
            }
        }
        $link = $xpath->query('ancestor::a[1]', $image)->item(0);
        if ($link instanceof DOMElement) {
            return ['text' => $this->label($link->getAttribute('aria-label') ?: $link->getAttribute('title')), 'url' => $link->getAttribute('href')];
        }
        $block = $xpath->query('ancestor::*[self::p or self::figure or self::div][1]', $image)->item(0);
        foreach ([true, false] as $forward) {
            $sibling = $block;
            for ($step = 0; $step < 5 && $sibling; $step++) {
                $sibling = $forward ? $sibling->nextSibling : $sibling->previousSibling;
                if (! $sibling || ($sibling instanceof DOMElement && $sibling->getElementsByTagName('img')->length > 0)) {
                    break;
                }
                $text = $this->label($sibling->textContent, 500);
                if ($text === '') {
                    continue;
                }
                // An adjacent purchase caption explicitly associates its linked product with this image.
                if (! preg_match('/\bkupite\b/iu', $text)) {
                    break;
                }
                $url = $sibling instanceof DOMElement ? $sibling->getElementsByTagName('a')->item(0)?->getAttribute('href') : '';
                if (! $url && preg_match('~https?://[^\s<>]+~iu', $text, $match)) {
                    $url = rtrim($match[0], '.,;)');
                }
                $caption = trim(preg_split('/\b(?:kupite|https?:\/\/)/iu', $text, 2)[0], " \t\n\r\0\x0B,:–-");

                return ['text' => $this->label($caption), 'url' => (string) $url];
            }
        }

        return ['text' => '', 'url' => ''];
    }

    private function label(string $text, int $limit = 240): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: '');

        return mb_strlen($text) <= $limit ? $text : '';
    }

    /** Resolve only the bounded set of links next to images, never scan the product catalog. */
    private function productNames(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls)));
        $references = [];
        $paths = [];
        $sourceIds = [];
        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host !== '' && ! in_array($host, ['watchline.hr', 'www.watchline.hr', strtolower(request()->getHost()), strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))], true)) {
                continue;
            }
            $path = HerreraLegacyUrlService::normalizePath((string) parse_url($url, PHP_URL_PATH));
            if (! $path) {
                continue;
            }
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (isset($query['product_id']) && ctype_digit((string) $query['product_id'])) {
                $sourceIds[] = (int) $query['product_id'];
                $references[$url] = ['source' => (int) $query['product_id']];
            } elseif (str_starts_with($path, '/product/')) {
                $references[$url] = ['slug' => rawurldecode(substr($path, 9))];
            } else {
                $leaf = '/'.basename($path);
                $paths[$url] = [$path, $leaf];
            }
        }
        $hashes = collect($paths)->flatten()->unique()->map(fn ($path) => hash('sha256', $path))->all();
        $legacy = $hashes === [] ? collect() : DB::table('herrera_legacy_urls')->whereIn('path_hash', $hashes)->get()->keyBy('path');
        foreach ($paths as $url => $candidates) {
            foreach ($candidates as $path) {
                $row = $legacy->get($path);
                if ($row && preg_match('/^product_id=(\d+)$/', $row->source_query, $match)) {
                    $sourceIds[] = (int) $match[1];
                    $references[$url] = ['source' => (int) $match[1]];
                    break;
                }
            }
        }
        $maps = $sourceIds === [] ? collect() : DB::table('herrera_import_maps')->where('source', 'herrera-opencart')->where('entity', 'product')
            ->whereIn('source_id', array_unique($sourceIds))->pluck('target_id', 'source_id');
        $ids = $maps->values()->all();
        $slugs = collect($references)->pluck('slug')->filter()->all();
        if ($ids === [] && $slugs === []) {
            return [];
        }
        $translations = DB::table('product_translations')->whereIn('locale', array_unique([app()->getLocale(), (string) config('app.locale'), 'hr']))
            ->where(fn ($query) => $query->whereIn('product_id', $ids)->orWhereIn('slug', $slugs))
            ->orderByRaw('CASE WHEN locale = ? THEN 0 WHEN locale = ? THEN 1 ELSE 2 END', [app()->getLocale(), 'hr'])->get();
        $result = [];
        foreach ($references as $url => $reference) {
            $id = isset($reference['source']) ? $maps->get($reference['source']) : null;
            $translation = $id ? $translations->firstWhere('product_id', $id) : $translations->firstWhere('slug', $reference['slug'] ?? '');
            if ($translation) {
                $result[$url] = $this->label($translation->name);
            }
        }

        return $result;
    }

    private function localDimensions(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && ! in_array($host, ['watchline.hr', 'www.watchline.hr', strtolower(request()->getHost()), strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))], true)) {
            return null;
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        $file = realpath(public_path(ltrim($path, '/')));
        $public = realpath(public_path()).DIRECTORY_SEPARATOR;
        if (! $file || ! str_starts_with($file, $public) || ! is_file($file)) {
            return null;
        }
        $size = @getimagesize($file);

        return $size && $size[0] > 0 && $size[1] > 0 ? [$size[0], $size[1]] : null;
    }
}
