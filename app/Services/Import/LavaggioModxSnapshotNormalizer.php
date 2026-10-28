<?php

namespace App\Services\Import;

use RuntimeException;

/** Turns the read-only MODX resource export into native content import input. */
class LavaggioModxSnapshotNormalizer
{
    public function normalize(array $raw): array
    {
        $origin = rtrim((string) ($raw['source_origin'] ?? 'https://www.lavaggio.hr'), '/');
        if (! in_array(strtolower((string) parse_url($origin, PHP_URL_HOST)), ['lavaggio.hr', 'www.lavaggio.hr'], true)) {
            throw new RuntimeException('The source must be the public Lavaggio website.');
        }
        $inactiveResourceIds = array_values(array_unique(array_filter(array_map('intval', [57, 58, 74, 77, 79, 89, 339, ...(array) ($raw['inactive_resource_ids'] ?? [])]), fn (int $id): bool => $id > 0)));

        $sources = [];
        foreach ((array) ($raw['resources'] ?? []) as $resource) {
            $id = (int) ($resource['id'] ?? 0);
            if ($id <= 0 || isset($sources[$id])) {
                throw new RuntimeException('Each MODX resource must have a unique positive ID.');
            }
            $resource['id'] = $id;
            $resource['parent'] = (int) ($resource['parent'] ?? 0);
            $resource['template'] = (int) ($resource['template'] ?? 0);
            $resource['source_url'] = $this->absolute((string) ($resource['uri'] ?? ''), $origin);
            $sources[$id] = $resource;
        }

        $resources = [];
        $faqs = [];
        foreach ($sources as $id => $resource) {
            // Home, contact, blog index, feedback form and sitemap already have native routes.
            if (in_array($id, [1, 2, 52, 184, 192, ...$inactiveResourceIds], true) || $resource['template'] === 23 || (isset($resource['published']) && ! (bool) $resource['published'])) {
                continue;
            }
            $tvs = (array) ($resource['tvs'] ?? []);
            $type = $resource['template'] === 21 ? 'blog' : 'page';
            $video = $this->unescape((string) ($tvs['videoembed'] ?? ''));
            $cover = $this->coverUrl($resource, $raw, $origin, $video);
            $body = '';
            if ($type === 'page' && $cover !== '') {
                $body .= '<figure><img src="'.$this->escape($cover).'" alt="'.$this->escape((string) ($resource['pagetitle'] ?? '')).'" /></figure>';
            }
            if ($type === 'page') {
                $intro = trim($this->unescape((string) ($resource['introtext'] ?? '')));
                if ($intro !== '') {
                    // The live content template places the lead after its image, inside the article.
                    $body .= preg_match('/<(?:p|div|h[1-6]|ul|ol|table|blockquote|section|article|figure)\b/i', $intro) ? $intro : '<p class="lavaggio-content-intro">'.$intro.'</p>';
                }
            }
            if ($video !== '') {
                $body .= $video;
            }
            $prices = $this->rows($tvs['cjenik'] ?? null, $id, 'cjenik');
            if ($prices !== []) {
                $body .= $this->priceTable($prices);
            }
            $body .= $this->unescape((string) ($resource['content'] ?? ''));
            $reviews = $this->rows($tvs['reviews'] ?? null, $id, 'reviews');
            if ($reviews !== []) {
                $body .= $this->reviews($reviews);
            }
            $questions = $this->rows($tvs['faq'] ?? null, $id, 'faq');
            if ($id === 72) {
                foreach ($questions as $index => $question) {
                    $faqs[] = [
                        'id' => $id.'-'.($question['MIGX_id'] ?? $index + 1),
                        'question' => $this->unescape((string) ($question['question'] ?? '')),
                        'answer_html' => $this->unescape((string) ($question['answer'] ?? '')),
                        'group_code' => 'lavaggio', 'sort_order' => $index,
                        'source_url' => $resource['source_url'],
                    ];
                }
            } elseif ($questions !== []) {
                $body .= $this->questions($questions);
            }
            if ($resource['template'] === 0 && trim(strip_tags($body)) === '') {
                $children = array_filter($sources, fn (array $child): bool => $child['parent'] === $id && ! in_array($child['id'], $inactiveResourceIds, true) && (! isset($child['published']) || (bool) $child['published']));
                uasort($children, fn (array $a, array $b): int => [(int) ($a['menuindex'] ?? 0), $a['id']] <=> [(int) ($b['menuindex'] ?? 0), $b['id']]);
                $body .= '<ul>';
                foreach ($children as $child) {
                    $body .= '<li><a href="[[~'.$child['id'].']]">'.$this->escape((string) ($child['menutitle'] ?: $child['pagetitle'])).'</a></li>';
                }
                $body .= '</ul>';
            }
            $resources[] = array_replace($resource, [
                'type' => $type, 'body_html' => $body, 'cover_url' => $cover,
                // Retain the original TV values, including JSON, as import provenance.
                'tvs' => $tvs,
            ]);
        }

        return [
            'source_origin' => $origin,
            'exported_at' => $raw['exported_at'] ?? null,
            'resources' => $resources,
            'faqs' => $faqs,
            'url_overrides' => array_replace([1 => '/', 2 => '/contact', 52 => '/blog', 72 => '/faq', 184 => '/contact'], (array) ($raw['url_overrides'] ?? [])),
            'inactive_resource_ids' => $inactiveResourceIds,
            'source_resources' => array_values(array_map(fn (array $row): array => array_intersect_key($row, array_flip(['id', 'uri', 'alias', 'source_url'])), $sources)),
            'allowed_asset_hosts' => array_values(array_unique(['img.youtube.com', ...(array) ($raw['allowed_asset_hosts'] ?? [])])),
            'local_assets' => (array) ($raw['local_assets'] ?? []),
            'verified_video_covers' => (array) ($raw['verified_video_covers'] ?? []),
        ];
    }

    private function coverUrl(array $resource, array $raw, string $origin, string $video): string
    {
        $explicit = trim((string) ($resource['cover_url'] ?? $raw['cover_urls'][$resource['id']] ?? ''));
        $videoId = preg_match('~https://(?:www\.)?(?:youtube\.com|youtube-nocookie\.com)/embed/([A-Za-z0-9_-]{11})(?=[?"\x27/<\s]|$)~', $video, $match) ? $match[1] : null;
        if ($videoId !== null && ($explicit === '' || str_starts_with($explicit, 'https://img.youtube.com/vi/'.$videoId.'/'))) {
            $verified = (array) ($raw['verified_video_covers'][$videoId] ?? []);
            $url = (string) ($verified['url'] ?? '');
            $width = (int) ($verified['width'] ?? 0);
            $height = (int) ($verified['height'] ?? 0);
            // Candidate availability and binary dimensions are verified before
            // snapshot creation. Reject YouTube's HTTP-200 placeholder sizes.
            if ($width >= 320 && $height >= 180 && $width * $height > 480 * 360
                && preg_match('~\Ahttps://img\.youtube\.com/vi/'.preg_quote($videoId, '~').'/[a-zA-Z0-9_-]+\.(?:jpg|webp)\z~D', $url)) {
                return $url;
            }
        }
        if ($explicit !== '') {
            return $this->absolute($explicit, $origin);
        }
        $image = trim((string) ($resource['tvs']['blog-image'] ?? ''));
        if ($image !== '') {
            if (preg_match('~^(?:https?://|/)~i', $image)) {
                return $this->absolute($image, $origin);
            }
            $assetBase = trim((string) ($raw['image_base_url'] ?? ''));
            if ($assetBase !== '') {
                return $this->absolute(rtrim($assetBase, '/').'/'.$image, $origin);
            }
        }
        if ($videoId !== null) {
            return 'https://img.youtube.com/vi/'.$videoId.'/hqdefault.jpg';
        }

        return '';
    }

    private function rows(mixed $value, int $resourceId, string $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        for ($pass = 0; is_string($value) && $pass < 3; $pass++) {
            try {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Invalid MODX '.$field.' JSON in resource '.$resourceId.'.', 0, $exception);
            }
        }
        if (! is_array($value) || ! array_is_list($value) || count(array_filter($value, 'is_array')) !== count($value)) {
            throw new RuntimeException('Expected MODX '.$field.' rows in resource '.$resourceId.'.');
        }

        return $value;
    }

    private function priceTable(array $rows): string
    {
        $html = '<table><thead><tr><th scope="col">Usluga</th><th scope="col">Cijena</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $title = $this->escape($this->unescape((string) ($row['title'] ?? '')));
            $subtitle = $this->escape($this->unescape((string) ($row['subtitle'] ?? '')));
            $details = '';
            foreach ($row as $key => $value) {
                if (str_starts_with((string) $key, 'listitem_') && trim((string) $value) !== '') {
                    $details .= '<li>'.$this->escape($this->unescape((string) $value)).'</li>';
                }
            }
            $html .= '<tr><td>'.$title.($subtitle !== '' ? '<br />'.$subtitle : '').($details !== '' ? '<ul>'.$details.'</ul>' : '').'</td><td>'.$this->escape($this->unescape((string) ($row['price'] ?? ''))).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    private function reviews(array $rows): string
    {
        $html = '';
        foreach ($rows as $row) {
            $html .= '<figure><blockquote><p>'.$this->escape($this->unescape((string) ($row['comment'] ?? ''))).'</p></blockquote><figcaption>'.$this->escape($this->unescape((string) ($row['name'] ?? ''))).'</figcaption></figure>';
        }

        return $html;
    }

    private function questions(array $rows): string
    {
        $html = '';
        foreach ($rows as $row) {
            $html .= '<details><summary>'.$this->escape($this->unescape((string) ($row['question'] ?? ''))).'</summary>'.$this->unescape((string) ($row['answer'] ?? '')).'</details>';
        }

        return $html;
    }

    private function unescape(string $value): string
    {
        // Some MIGX editors stored an extra JSON escape layer inside each field.
        for ($pass = 0; $pass < 3; $pass++) {
            $decoded = preg_replace_callback('/\\\\(?:u[0-9a-fA-F]{4}(?:\\\\u[0-9a-fA-F]{4})?|["\/\\\\nrtbf])/', function (array $match): string {
                $decoded = json_decode('"'.$match[0].'"');

                return is_string($decoded) ? $decoded : $match[0];
            }, $value) ?? $value;
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }

        return $value;
    }

    private function absolute(string $url, string $origin): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        return $origin.'/'.ltrim($url, '/');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
