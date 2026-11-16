<?php

namespace App\Services\Integrations\Fira;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FiraPriceArchiveService
{
    public function entries(): array
    {
        return Cache::remember('lavaggio:fira-price-archive', now()->addMinutes(5), function (): array {
            try {
                $response = Http::accept('text/html')->connectTimeout(3)->timeout(8)->withoutRedirecting()
                    ->get(FiraSettingsService::PRICE_LIST_URL);

                return $response->successful() ? $this->parse($response->body()) : [];
            } catch (\Throwable) {
                // The permanent FIRA links remain available if the archive cannot be read.
                return [];
            }
        });
    }

    public function parse(string $html): array
    {
        if (trim($html) === '' || strlen($html) > 2_000_000) {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            $document->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8'), LIBXML_NONET);
            $xpath = new DOMXPath($document);
            $base = FiraSettingsService::PRICE_LIST_URL;
            $basePath = parse_url($base, PHP_URL_PATH);
            $entries = [];
            foreach ($xpath->query('//li//a[@href]') as $link) {
                $href = $link->getAttribute('href');
                if (str_starts_with($href, $base.'/')) {
                    $file = substr($href, strlen($base) + 1);
                } elseif (str_starts_with($href, $basePath.'/')) {
                    $file = substr($href, strlen($basePath) + 1);
                } else {
                    $file = $href;
                }
                // Never render remote HTML or permit arbitrary external download destinations.
                if (! preg_match('/^\d+\.csv$/D', $file)) {
                    continue;
                }
                $row = $xpath->query('ancestor::li[1]', $link)->item(0);
                if (! preg_match('/\b(\d{2}\.\d{2}\.\d{4}\.)\s*(\d{2}:\d{2})/', $row->textContent, $date)) {
                    continue;
                }
                $entries[$file] = ['date' => $date[1].' '.$date[2], 'url' => $base.'/'.$file];
            }

            return array_values($entries);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
