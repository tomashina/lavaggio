<?php

namespace App\Support\Lavaggio;

use Illuminate\Support\Str;

/** The narrowly scoped content decisions approved for the 4 November feedback. */
class NovemberFeedback
{
    public const RETIRED_PAGES = [69, 70, 87, 92, 154, 155, 322];

    public const RETIRED_FAQS = ['72-1', '72-6'];

    public static function snapshot(array $snapshot): array
    {
        $snapshot['inactive_resource_ids'] = array_values(array_unique([...($snapshot['inactive_resource_ids'] ?? []), ...array_diff(self::RETIRED_PAGES, [70])]));
        $snapshot['resources'] = array_values(array_filter(array_map(fn (array $resource): array => self::resource($resource), $snapshot['resources'] ?? []), fn (array $resource): bool => (int) ($resource['id'] ?? 0) !== 70));
        $snapshot['url_overrides'][70] = '/cjenik';
        $snapshot['url_overrides'][88] = '/cjenik';
        $snapshot['faqs'] = array_values(array_filter($snapshot['faqs'] ?? [], fn (array $faq): bool => ! in_array((string) ($faq['id'] ?? ''), self::RETIRED_FAQS, true)));
        $snapshot['faqs'] = array_map(function (array $faq): array {
            if ($answer = self::faqAnswer((string) ($faq['id'] ?? ''))) {
                $faq['answer_html'] = $answer;
            }

            return $faq;
        }, $snapshot['faqs']);
        foreach ($snapshot['url_overrides'] ?? [] as $id => $url) {
            $snapshot['url_overrides'][$id] = PublicUrls::url($url);
        }

        return $snapshot;
    }

    public static function resource(array $resource): array
    {
        $id = (int) ($resource['id'] ?? 0);
        $body = (string) ($resource['body_html'] ?? '');
        if ($id === 174) {
            // The old opening-hours graphic also advertised the closed service.
            $body = preg_replace('#^.*?(?=<h3>🧼 Uslužna praonica)#su', '', $body);
            $resource['longtitle'] = 'Radno vrijeme LAVAGGIO praonice u Barbanu';
            $resource['description'] = 'Uslužno pranje, predaja i preuzimanje rublja: ponedjeljak – petak od 08h do 16h.';
        } elseif ($id === 86) {
            $body = preg_replace('#^.*?(?=<p><strong>USLUŽNA PRAONICA</strong>)#su', '', $body);
        } elseif ($id === 80) {
            foreach (['introtext', 'excerpt', 'description', 'meta_description'] as $field) {
                if (isset($resource[$field])) {
                    $resource[$field] = str_replace('u samoposlužnoj praonici rublja koja je registrirana', 'u uslužnoj praonici rublja koja je registrirana', $resource[$field]);
                }
            }
            $body = str_replace('u samoposlužnoj praonici rublja koja je registrirana', 'u uslužnoj praonici rublja koja je registrirana', $body);
            $body = preg_replace('#<p\b[^>]*>[^<]*(?:Posljednje pranje može započeti|Održavajte red i čistoću u samoposlužnim|Samoposlužne usluge se plaćaju).*?</p>#su', '', $body);
            $body = preg_replace('#<a href="\[\[~87\]\]">samoposlužno pranje ovdje</a>,\s*#u', '', $body);
        } elseif ($id === 88) {
            // The client explicitly confirmed that all current prices remain the same.
            $body = str_replace('Cijene vrijede od: 01.06.2026', 'Cijene vrijede od: 10.09.2026', $body);
        }
        if ($id === 68) {
            $resource['description'] = 'Uslužno pranje i peglanje rublja, prikup i dostava, ozonsko pranje te informacije o Lavaggio praonici.';
        }
        if ($id === 68 || $id === 70) {
            $body = preg_replace('#<li>\s*<a href="\[\[~(?:69|87|322)\]\]">.*?</a>\s*</li>#su', '', $body);
        }
        $resource['body_html'] = $body;

        return $resource;
    }

    /** The exact defaults used by the source importer, also used to recognize untouched SEO. */
    public static function metadataDefaults(array $resource): array
    {
        $plain = static fn (string $html): string => trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
        $excerpt = $plain((string) ($resource['introtext'] ?? $resource['excerpt'] ?? ''));
        $description = $plain((string) ($resource['meta_description'] ?? $resource['description'] ?? ''));
        $title = $plain((string) ($resource['title'] ?? $resource['pagetitle'] ?? ''));

        return [
            'excerpt' => $excerpt ?: null,
            'meta_title' => Str::limit($plain((string) ($resource['meta_title'] ?? $resource['longtitle'] ?? '')) ?: $title.' | Lavaggio', 255, ''),
            'meta_description' => $description ?: Str::limit($plain($excerpt ?: (string) ($resource['body_html'] ?? '')), 300, ''),
        ];
    }

    public static function faqAnswer(string $sourceId): ?string
    {
        return match ($sourceId) {
            '72-2' => '<p>Da, LAVAGGIO® praonica rublja ima uslugu ostavljanja rublja i kasnijeg preuzimanja. Mi za vas operemo, osušimo, složimo, a ukoliko je potrebno i opeglamo.</p>',
            '72-3' => '<p>LAVAGGIO® uslužno pranje rublja otvoreno je od ponedjeljka do petka od 08h do 16h. Subotom, nedjeljom i blagdanima smo zatvoreni.</p><p><a href="[[~174]]">Radno vrijeme praonice</a></p>',
            '72-4' => '<p>Cijene uslužnog pranja, sušenja i peglanja rublja pogledajte u <a href="[[~88]]">cjeniku</a>.</p><p><a href="[[~86]]">Načini plaćanja</a></p>',
            '72-5' => '<p>Usluge pranja možete platiti gotovinski, bankovnim karticama ili bankovnom transakcijom prema uvjetima navedenima na stranici <a href="[[~86]]">Načini plaćanja</a>.</p>',
            '72-11' => '<p>Ne trebate donositi deterdžent. U našoj usluzi pranja koristimo profesionalni deterdžent i ne dodajemo omekšivač.</p>',
            default => null,
        };
    }
}
