<?php

namespace App\Services\Import;

use App\Support\ImportedDescriptionHtmlCleaner;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Request;

class WatchlineContentNormalizer
{
    private array $links = [];

    public int $imagesRewritten = 0;

    public int $linksRewritten = 0;

    public function __construct(private readonly ImportedDescriptionHtmlCleaner $cleaner, private readonly HerreraLegacyUrlService $legacyUrls) {}

    public function normalize(string $html, string $locale = 'hr'): string
    {
        $html = $this->cleaner->clean($html);
        if ($html === '') {
            return '';
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML(mb_encode_numericentity('<div data-watchline-content-root="1">'.$html.'</div>', [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            $root = (new DOMXPath($document))->query('//*[@data-watchline-content-root="1"]')->item(0);
            if (! $root instanceof DOMElement) {
                return $html;
            }
            foreach ($root->getElementsByTagName('*') as $element) {
                foreach (['src', 'poster', 'href'] as $attribute) {
                    if (! $element->hasAttribute($attribute)) {
                        continue;
                    }
                    $url = $element->getAttribute($attribute);
                    $normalized = $this->url($url, $attribute, $locale);
                    if ($normalized !== $url) {
                        $element->setAttribute($attribute, $normalized);
                        if ($attribute === 'href' && ! str_starts_with($normalized, 'https://www.watchline.hr/image/')) {
                            $this->linksRewritten++;
                        } else {
                            $this->imagesRewritten++;
                        }
                    }
                }
            }
            $result = '';
            foreach ($root->childNodes as $node) {
                $result .= $document->saveHTML($node);
            }

            return trim($result);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function url(string $url, string $attribute, string $locale): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true))) {
            return $url;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && ! in_array($host, ['watchline.hr', 'www.watchline.hr', strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))], true)) {
            return $url;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $path) || array_intersect(explode('/', $path), ['.', '..']) !== []) {
            return $url;
        }
        $path = '/'.ltrim($path, '/');
        if (str_starts_with($path, '/catalog/') && $attribute !== 'href') {
            $path = '/image'.$path;
        }
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';
        if (str_starts_with($path, '/image/')) {
            return 'https://www.watchline.hr'.implode('/', array_map('rawurlencode', explode('/', $path))).$query.$fragment;
        }
        if ($attribute !== 'href' || ($path === '/' && $query === '' && $host === '')) {
            return $url;
        }
        $key = $locale.'|'.$path.$query;
        if (! array_key_exists($key, $this->links)) {
            $request = Request::create(rtrim(config('app.url'), '/').$path.$query);
            $route = $request->query('route');
            if ($route === 'account/register') {
                $destination = route('front.auth.register', absolute: false);
            } else {
                $resolved = $this->legacyUrls->resolve($request);
                $destination = ($resolved['status'] ?? null) === 'redirect' ? ($resolved['destination'] ?? null) : null;
            }
            if (! $destination && in_array($path, ['/', '/shop', '/categories', '/brendovi', '/contact', '/blog', '/cart', '/checkout', '/auth/login', '/auth/register'], true)) {
                $destination = $path.$query;
            }
            $this->links[$key] = $destination;
        }

        return $this->links[$key] ? $this->links[$key].$fragment : $url;
    }
}
