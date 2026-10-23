<?php

namespace App\Console\Commands;

use App\Support\ImportedDescriptionHtmlCleaner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class LibermediaNormalizeContent extends Command
{
    protected $signature = 'libermedia:normalize-content {--download-missing : Copy missing legacy blog images from the public shop}';

    protected $description = 'Clean imported HTML and repair OpenCart image paths, preserving public external references';

    private array $missing = [];

    private int $rewritten = 0;

    public function handle(ImportedDescriptionHtmlCleaner $cleaner): int
    {
        if (! app()->environment('local') || config('app.name') !== 'Libermedia') {
            return self::FAILURE;
        }
        foreach (['content_blog_post_translations', 'content_info_page_translations', 'product_translations', 'category_translations'] as $table) {
            $column = in_array($table, ['product_translations', 'category_translations']) ? 'description' : 'body_html';
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column, $cleaner): void {
                foreach ($rows as $row) {
                    $html = $cleaner->clean((string) $row->$column);
                    $html = preg_replace_callback('~\b(src|href)=("|\x27)(.*?)\2~is', function ($m): string {
                        $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $host = parse_url($url, PHP_URL_HOST);
                        $ownHost = ! $host || in_array(strtolower($host), ['www.liber-media.hr', 'liber-media.hr', 'libermedia.dmb.hr'], true);
                        if (! $ownHost) {
                            return $m[0];
                        }
                        $path = rawurldecode(parse_url($url, PHP_URL_PATH) ?? '');
                        if ($m[1] === 'src' && str_starts_with(ltrim($path, '/'), 'image/')) {
                            $path = '/'.ltrim($path, '/');
                            if (str_contains($path, '..') || str_contains($path, "\0")) {
                                return $m[0];
                            }
                            $original = $this->originalImage($path);
                            if ($original) {
                                $path = $original;
                            } else {
                                $this->missing[$path] = true;
                            }
                            $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
                            if ($encoded !== $url) {
                                $this->rewritten++;
                            }

                            return $m[1].'='.$m[2].htmlspecialchars($encoded, ENT_QUOTES | ENT_HTML5, 'UTF-8').$m[2];
                        }
                        if ($m[1] === 'href' && $host) {
                            return 'href='.$m[2].htmlspecialchars('/'.ltrim($path, '/').(($query = parse_url($url, PHP_URL_QUERY)) ? '?'.$query : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8').$m[2];
                        }
                        if ($m[1] === 'src' && ! str_starts_with($url, 'data:')) {
                            $this->missing[$path] = true;
                        }

                        return $m[0];
                    }, $html);
                    $fields = [$column => $html];
                    if (isset($row->excerpt)) {
                        $fields['excerpt'] = trim(html_entity_decode(strip_tags($row->excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }
                    DB::table($table)->where('id', $row->id)->update($fields);
                }
            });
            $this->info($table.' formatted');
        }
        $downloaded = 0;
        if ($this->option('download-missing')) {
            foreach (array_chunk(array_keys($this->missing), 8) as $paths) {
                $responses = Http::pool(fn ($pool) => array_map(fn ($path) => $pool->as($path)->timeout(8)->connectTimeout(4)->get('https://www.liber-media.hr'.implode('/', array_map('rawurlencode', explode('/', $path)))), $paths));
                foreach ($paths as $path) {
                    $response = $responses[$path] ?? null;
                    if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
                        continue;
                    }
                    $bytes = $response->body();
                    if (strlen($bytes) > 15 * 1024 * 1024 || ! @getimagesizefromstring($bytes)) {
                        continue;
                    }
                    $target = public_path(ltrim($path, '/'));
                    if (! is_dir(dirname($target))) {
                        mkdir(dirname($target), 0755, true);
                    }
                    file_put_contents($target, $bytes);
                    unset($this->missing[$path]);
                    $downloaded++;
                }
                $this->line('Legacy images copied: '.$downloaded);
            }
        }
        // Unavailable legacy placeholders should not render as broken image icons.
        foreach (['content_blog_post_translations', 'content_info_page_translations'] as $table) {
            foreach (DB::table($table)->get(['id', 'body_html']) as $row) {
                $html = preg_replace_callback('~<img\b[^>]*\bsrc=("|\x27)(.*?)\1[^>]*>~is', function ($m): string {
                    $path = rawurldecode(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    return isset($this->missing[$path]) ? '' : $m[0];
                }, (string) $row->body_html);
                if ($html !== $row->body_html) {
                    DB::table($table)->where('id', $row->id)->update(['body_html' => $html]);
                }
            }
        }
        $emptyPosts = [];
        foreach (DB::table('content_blog_post_translations')->get(['post_id', 'body_html', 'excerpt']) as $translation) {
            if (trim(strip_tags((string) $translation->body_html)) === '' && trim((string) $translation->excerpt) === ''
                && ! preg_match('~<(?:img|video|audio)\b~i', (string) $translation->body_html)) {
                $emptyPosts[] = $translation->post_id;
            }
        }
        if ($emptyPosts) {
            DB::table('content_blog_posts')->whereIn('id', $emptyPosts)->update(['is_active' => false]);
        }
        $report = ['empty_posts_kept_unpublished' => $emptyPosts, 'rewritten_images' => $this->rewritten, 'downloaded_images' => $downloaded, 'missing_images' => array_keys($this->missing)];
        Storage::disk('local')->put('libermedia-migration/content-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Image paths repaired: '.$this->rewritten.'; missing images: '.count($this->missing));
        app(\App\Services\Front\GuestStorefrontCache::class)->invalidate();

        return self::SUCCESS;
    }

    private function originalImage(string $path): ?string
    {
        if (is_file(public_path(ltrim($path, '/')))) {
            return $path;
        }
        $original = preg_replace('~^/image/cache(?:webp)?/~', '/image/', $path);
        $original = preg_replace('~-\d+x\d+(?=\.[^.]+$)~', '', $original);
        $stem = preg_replace('~\.[^.]+$~', '', $original);
        foreach ([$original, $stem.'.jpg', $stem.'.png', $stem.'.jpeg', $stem.'.webp', $stem.'.JPG'] as $candidate) {
            if (is_file(public_path(ltrim($candidate, '/')))) {
                return $candidate;
            }
        }

        return null;
    }
}
