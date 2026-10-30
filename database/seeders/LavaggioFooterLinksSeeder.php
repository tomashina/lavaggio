<?php

namespace Database\Seeders;

use App\Models\Content\Page\InfoPage;
use App\Models\Content\Page\InfoPageTranslation;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class LavaggioFooterLinksSeeder extends Seeder
{
    public const SETTING_KEY = 'lavaggio_footer_legal_links';

    public const PLACEHOLDER_BODY = '<p>Sadržaj ove stranice je u pripremi.</p>';

    public const PLACEHOLDER_PAGES = [
        ['webshop-nacini-placanja', 'Načini plaćanja'],
        ['povrati-i-reklamacije', 'Povrati i reklamacije'],
        ['dostava', 'Dostava'],
        ['raskid-ugovora-i-prigovor', 'Raskid ugovora / prigovor'],
        ['uvjeti-kupnje-i-poslovanja', 'Uvjeti kupnje i poslovanja'],
        ['izjava-o-pristupacnosti', 'Izjava o pristupačnosti'],
    ];

    public function run(): void
    {
        if (! app()->environment('local') || config('storefront.home') !== 'lavaggio'
            || config('database.default') !== 'sqlite' || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Footer examples are restricted to the local Lavaggio SQLite preview.');
        }

        $privacy = $this->existingTranslation('lavaggio-modx-84');
        $jobs = $this->existingTranslation('lavaggio-modx-81');
        $this->backupLocalDatabase();

        DB::transaction(function () use ($privacy, $jobs): void {
            $urls = [];
            foreach (self::PLACEHOLDER_PAGES as [$slug, $title]) {
                $code = 'lavaggio-shop-'.$slug;
                $page = InfoPage::query()->firstOrCreate(['code' => $code], [
                    'layout' => 'legal', 'is_active' => true, 'show_in_footer' => false,
                    'published_at' => now(), 'sort_order' => 200,
                    'payload' => ['local_demo' => true, 'lavaggio_footer_placeholder' => true],
                ]);
                // Editors own all existing page content and visibility settings.
                $translation = $page->translations()->where('locale', 'hr')->first();
                if (! $translation) {
                    if (InfoPageTranslation::query()->where('locale', 'hr')->where('slug', $slug)->exists()) {
                        throw new RuntimeException('A different page already uses the footer slug: '.$slug);
                    }
                    $translation = $page->translations()->create([
                        'locale' => 'hr', 'title' => $title, 'slug' => $slug,
                        'body_html' => self::PLACEHOLDER_BODY, 'meta_title' => $title,
                        'payload' => ['local_demo' => true, 'lavaggio_footer_placeholder' => true],
                    ]);
                }
                $urls[$slug] = route('pages.show', $translation->slug, false);
            }

            $body = (string) $privacy->body_html;
            foreach ([
                'zastita-podataka' => 'TKO OBRAĐUJE VAŠE OSOBNE PODATKE?',
                'privatnost-i-kolacici' => 'KORIŠTENJE INTERNET KOLAČIĆA (COOKIES)',
                'podnosenje-prigovora' => 'NAČIN PODNOŠENJA PISANOG PRIGOVORA POTROŠAČA',
            ] as $anchor => $heading) {
                $body = $this->anchorHeading($body, $anchor, $heading);
            }
            if ($body !== $privacy->body_html) {
                // Add only anchor attributes; retain the imported policy wording.
                $privacy->update(['body_html' => $body]);
            }
            $privacyUrl = route('pages.show', $privacy->slug, false);
            $links = [
                ['label' => 'Načini plaćanja', 'url' => $urls['webshop-nacini-placanja']],
                ['label' => 'Povrati i reklamacije', 'url' => $urls['povrati-i-reklamacije']],
                ['label' => 'Dostava', 'url' => $urls['dostava']],
                ['label' => 'Raskid ugovora / prigovor', 'url' => $urls['raskid-ugovora-i-prigovor']],
                ['label' => 'Zaštita podataka', 'url' => $privacyUrl.'#zastita-podataka'],
                ['label' => 'Podnošenje prigovora', 'url' => $privacyUrl.'#podnosenje-prigovora'],
                ['label' => 'Privatnost i kolačići', 'url' => $privacyUrl.'#privatnost-i-kolacici'],
                ['label' => 'Uvjeti kupnje i poslovanja', 'url' => $urls['uvjeti-kupnje-i-poslovanja']],
                ['label' => 'Izjava o pristupačnosti', 'url' => $urls['izjava-o-pristupacnosti']],
                ['label' => 'Zapošljavamo', 'url' => route('pages.show', $jobs->slug, false)],
            ];
            $settings = app(SystemSettingsService::class);
            if (! array_key_exists(self::SETTING_KEY, $settings->all())) {
                $settings->put(self::SETTING_KEY, $links);
            }
        });

        ContentBlockResolver::bumpCacheVersion();
        app(GuestStorefrontCache::class)->invalidate();
        $this->command?->info('Lavaggio footer links are ready. Existing page content and edited links were preserved.');
    }

    private function existingTranslation(string $code): InfoPageTranslation
    {
        $page = InfoPage::query()->where('code', $code)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->firstOrFail();

        return $page->translations()->where('locale', 'hr')->whereNotNull('slug')->where('slug', '!=', '')->firstOrFail();
    }

    private function anchorHeading(string $html, string $anchor, string $heading): string
    {
        if (preg_match('/\bid\s*=\s*["\']'.preg_quote($anchor, '/').'["\']/u', $html)) {
            return $html;
        }
        $pattern = '/<strong\b[^>]*>(?=\s*(?:<span\b[^>]*>)?[\s\x{00A0}]*'.preg_quote($heading, '/').')/u';
        $count = 0;
        $result = preg_replace_callback($pattern, function (array $match) use ($anchor): string {
            return preg_match('/\bid\s*=/u', $match[0])
                ? '<span id="'.$anchor.'"></span>'.$match[0]
                : substr($match[0], 0, -1).' id="'.$anchor.'">';
        }, $html, 1, $count);

        // If an editor replaced a heading, preserve that text and retain a working page anchor.
        return $count > 0 ? $result : '<span id="'.$anchor.'"></span>'.$html;
    }

    private function backupLocalDatabase(): void
    {
        $active = collect(DB::connection()->select('PRAGMA database_list'))->firstWhere('name', 'main');
        // An in-memory SQLite connection has nothing persistent to back up.
        if ($active && $active->file === '') {
            return;
        }
        $expected = realpath(database_path('database.sqlite'));
        if (! $active || ! $expected || $active->file !== $expected || is_link(database_path('database.sqlite'))) {
            throw new RuntimeException('The active database is not the verified local Lavaggio destination.');
        }
        $directory = storage_path('app/private/lavaggio-demo-backups');
        File::ensureDirectoryExists($directory);
        $backup = $directory.'/before-footer-links-'.now()->format('Ymd-His-u').'.sqlite';
        DB::connection()->getPdo()->exec('VACUUM INTO '.DB::connection()->getPdo()->quote($backup));
        chmod($backup, 0600);
        $this->command?->info('Database backup: '.$backup);
    }
}
