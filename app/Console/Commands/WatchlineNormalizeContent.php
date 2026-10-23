<?php

namespace App\Console\Commands;

use App\Services\Front\GuestStorefrontCache;
use App\Services\Import\WatchlineContentNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WatchlineNormalizeContent extends Command
{
    protected $signature = 'watchline:normalize-content';

    protected $description = 'Normalize imported Watchline content links while keeping all source images on the live image origin';

    public function handle(WatchlineContentNormalizer $normalizer, GuestStorefrontCache $cache): int
    {
        if (! app()->environment('local') || strcasecmp((string) config('app.name'), 'Watchline') !== 0) {
            $this->error('Restricted to the local Watchline preview.');

            return self::FAILURE;
        }
        $report = [];
        foreach ([
            'product_translations' => 'description', 'category_translations' => 'description',
            'catalog_manufacturer_translations' => 'description',
            'content_blog_post_translations' => 'body_html', 'content_info_page_translations' => 'body_html',
        ] as $table => $column) {
            if (! DB::getSchemaBuilder()->hasColumn($table, $column)) {
                continue;
            }
            $changed = 0;
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column, $normalizer, &$changed): void {
                foreach ($rows as $row) {
                    $payload = json_decode((string) ($row->payload ?? ''), true);
                    if (! is_array($payload) || ! isset($payload['opencart'])) {
                        continue;
                    }
                    $html = $normalizer->normalize((string) $row->$column, (string) ($row->locale ?? 'hr'));
                    $fields = [];
                    if ($html !== (string) $row->$column) {
                        $fields[$column] = $html;
                    }
                    if (isset($row->excerpt)) {
                        $excerpt = trim(html_entity_decode(strip_tags((string) $row->excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if ($excerpt !== $row->excerpt) {
                            $fields['excerpt'] = $excerpt;
                        }
                    }
                    if ($fields !== []) {
                        DB::table($table)->where('id', $row->id)->update($fields);
                        $changed++;
                    }
                }
            });
            $report[$table] = $changed;
        }
        $report += ['images_rewritten' => $normalizer->imagesRewritten, 'internal_links_rewritten' => $normalizer->linksRewritten];
        Storage::disk('local')->put('watchline-migration/content-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $cache->invalidate();
        $this->table(['Content', 'Updated'], collect($report)->map(fn ($count, $name) => [$name, $count])->all());

        return self::SUCCESS;
    }
}
