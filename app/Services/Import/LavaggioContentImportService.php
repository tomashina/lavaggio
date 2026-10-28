<?php

namespace App\Services\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Support\Faq;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/** Imports public MODX content into the ordinary, editable content modules. */
class LavaggioContentImportService
{
    private string $sourceOrigin = 'https://www.lavaggio.hr';

    private array $assetHosts = [];

    private array $resourceUrls = [];

    private array $pathUrls = [];

    private array $assets = [];

    private array $localAssets = [];

    private array $inactiveResourceIds = [];

    private array $blogCategoryIds = [];

    private array $warnings = [];

    private int $linksRewritten = 0;

    private int $tagsRemoved = 0;

    private int $inactiveLinksRemoved = 0;

    private const ASSET_DIRECTORY = 'assets/lavaggio/content';

    private const SOURCE_HOSTS = ['lavaggio.hr', 'www.lavaggio.hr'];

    /**
     * Resources accept id, parent, type (page/blog), pagetitle, uri, alias,
     * rendered_body_html/body_html/content, introtext, description, SEO fields,
     * publishedon, source_url, cover_url and scalar MODX template variables.
     * Optional url_overrides maps source resource IDs to native local paths.
     */
    public function preview(array $snapshot): array
    {
        $resources = $this->prepare($snapshot);

        return [
            'pages' => count(array_filter($resources, fn (array $row): bool => $row['type'] === 'page')),
            'posts' => count(array_filter($resources, fn (array $row): bool => $row['type'] === 'blog')),
            'faqs' => count($snapshot['faqs'] ?? []),
            'resource_urls' => $this->resourceUrls,
        ];
    }

    public function import(array $snapshot): array
    {
        $resources = $this->prepare($snapshot);
        $userId = User::query()->where('email', 'tomislav@agmedia.hr')->where('account_type', 'staff')->value('id');
        $prepared = [];

        // Finish source parsing and downloads before changing any database rows.
        foreach ($resources as $resource) {
            $resource['body'] = $this->normalizeHtml($this->resourceBody($resource), $resource);
            $cover = trim((string) ($resource['cover_url'] ?? ''));
            $resource['cover_path'] = $cover === '' ? null : $this->downloadImage($cover, $resource['source_url']);
            $prepared[] = $resource;
        }
        $faqs = [];
        foreach ((array) ($snapshot['faqs'] ?? []) as $index => $faq) {
            if (! is_array($faq) || (string) ($faq['id'] ?? '') === '' || trim((string) ($faq['question'] ?? '')) === '') {
                throw new RuntimeException('Every FAQ needs a stable source ID and a question.');
            }
            $faq['sort_order'] = (int) ($faq['sort_order'] ?? $index);
            $faq['body'] = $this->normalizeHtml((string) ($faq['answer_html'] ?? $faq['content'] ?? ''), $faq + ['source_url' => $this->sourceOrigin.'/']);
            $faqs[] = $faq;
        }
        if ($this->inactiveLinksRemoved > 0) {
            $this->warnings[] = 'Removed '.$this->inactiveLinksRemoved.' links to inactive MODX resources; their text and content were preserved.';
        }

        $ids = DB::transaction(function () use ($prepared, $faqs, $userId): array {
            $ids = ['page_ids' => [], 'blog_ids' => [], 'faq_ids' => []];
            foreach ($prepared as $resource) {
                $model = $resource['type'] === 'blog' ? BlogPost::class : InfoPage::class;
                $payload = [
                    'source' => 'lavaggio-modx',
                    'modx' => [
                        'id' => $resource['id'], 'parent' => (int) ($resource['parent'] ?? 0),
                        'uri' => (string) ($resource['uri'] ?? ''), 'source_url' => $resource['source_url'],
                        'template' => $resource['template'] ?? null, 'tvs' => $resource['tvs'] ?? [],
                        'editedon' => $resource['editedon'] ?? null,
                    ],
                ];
                $row = $model::query()->firstOrNew(['code' => $resource['code']]);
                $row->fill([
                    'is_active' => true,
                    'published_at' => $this->sourceDate($resource['publishedon'] ?? $resource['published_at'] ?? null),
                    'sort_order' => (int) ($resource['menuindex'] ?? $resource['sort_order'] ?? 0),
                    'payload' => array_replace_recursive((array) $row->payload, $payload),
                    'updated_by' => $userId,
                ]);
                if (! $row->exists) {
                    $row->created_by = $userId;
                }
                if ($row instanceof InfoPage) {
                    $row->layout = 'default';
                }
                $row->save();
                $excerpt = trim((string) ($resource['introtext'] ?? $resource['excerpt'] ?? ''));
                $description = $this->plain((string) ($resource['meta_description'] ?? $resource['description'] ?? ''));
                $row->translations()->updateOrCreate(['locale' => 'hr'], [
                    'title' => $resource['title'], 'slug' => $resource['slug'],
                    'excerpt' => $this->plain($excerpt) ?: null, 'body_html' => $resource['body'],
                    'meta_title' => Str::limit($this->plain((string) ($resource['meta_title'] ?? $resource['longtitle'] ?? '')) ?: $resource['title'].' | Lavaggio', 255, ''),
                    'meta_description' => $description ?: Str::limit($this->plain($excerpt ?: $resource['body']), 300, ''),
                    'payload' => ['source_url' => $resource['source_url'], 'source_id' => $resource['id']],
                ]);
                $ids[$row instanceof BlogPost ? 'blog_ids' : 'page_ids'][$resource['id']] = (int) $row->id;
                if ($row instanceof BlogPost) {
                    $this->importBlogCategories($row, $resource, $userId);
                }
                if ($row instanceof BlogPost && $resource['cover_path']) {
                    $this->attachCover($row, $resource['cover_path'], (string) ($resource['cover_url'] ?? ''));
                }
            }
            foreach ($faqs as $faq) {
                $row = Faq::query()->firstOrNew(['code' => 'lavaggio-modx-faq-'.$faq['id']]);
                $row->fill([
                    'is_active' => true, 'sort_order' => $faq['sort_order'],
                    'group_code' => $faq['group_code'] ?? 'lavaggio',
                    'payload' => array_replace((array) $row->payload, ['source' => 'lavaggio-modx', 'source_id' => $faq['id']]),
                    'updated_by' => $userId,
                ]);
                if (! $row->exists) {
                    $row->created_by = $userId;
                }
                $row->save();
                $row->translations()->updateOrCreate(['locale' => 'hr'], [
                    'question' => trim($faq['question']),
                    'slug' => $faq['slug'] ?? Str::slug($faq['question']).'-'.$faq['id'],
                    'answer_html' => $faq['body'],
                    'meta_title' => $faq['meta_title'] ?? $faq['question'],
                    'meta_description' => $faq['meta_description'] ?? Str::limit($this->plain($faq['body']), 300, ''),
                ]);
                $ids['faq_ids'][$faq['id']] = (int) $row->id;
            }

            return $ids;
        });

        return $ids + [
            'pages_imported' => count($ids['page_ids']), 'posts_imported' => count($ids['blog_ids']),
            'faqs_imported' => count($ids['faq_ids']), 'images_local' => count(array_filter($this->assets)),
            'blog_categories_imported' => count($this->blogCategoryIds),
            'links_rewritten' => $this->linksRewritten, 'modx_tags_removed' => $this->tagsRemoved,
            'inactive_links_removed' => $this->inactiveLinksRemoved,
            'resource_urls' => $this->resourceUrls, 'assets' => $this->assets,
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    private function prepare(array $snapshot): array
    {
        $this->sourceOrigin = rtrim((string) ($snapshot['source_origin'] ?? 'https://www.lavaggio.hr'), '/');
        if (! in_array(strtolower((string) parse_url($this->sourceOrigin, PHP_URL_HOST)), self::SOURCE_HOSTS, true)) {
            throw new RuntimeException('The snapshot must originate from the public Lavaggio website.');
        }
        $this->assetHosts = array_unique([...self::SOURCE_HOSTS, ...array_map('strtolower', (array) ($snapshot['allowed_asset_hosts'] ?? []))]);
        $this->localAssets = (array) ($snapshot['local_assets'] ?? []);
        $this->inactiveResourceIds = array_fill_keys(array_filter(array_map('intval', (array) ($snapshot['inactive_resource_ids'] ?? [])), fn (int $id): bool => $id > 0), true);
        $this->resourceUrls = $this->pathUrls = $this->assets = $this->warnings = [];
        $this->blogCategoryIds = [];
        $this->linksRewritten = $this->tagsRemoved = $this->inactiveLinksRemoved = 0;
        foreach ((array) ($snapshot['url_overrides'] ?? []) as $id => $url) {
            if ((int) $id > 0 && ! isset($this->inactiveResourceIds[(int) $id]) && is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $this->resourceUrls[(int) $id] = $url;
            }
        }
        $resources = [];
        $slugs = [];
        foreach ((array) ($snapshot['resources'] ?? []) as $resource) {
            if (! is_array($resource) || (int) ($resource['id'] ?? 0) <= 0) {
                throw new RuntimeException('Every resource needs a positive MODX resource ID.');
            }
            $id = (int) $resource['id'];
            if (isset($this->inactiveResourceIds[$id])) {
                continue;
            }
            if (isset($resources[$id])) {
                throw new RuntimeException('The snapshot contains a duplicate MODX resource ID.');
            }
            $type = (string) ($resource['type'] ?? 'page');
            if (! in_array($type, ['page', 'blog'], true)) {
                throw new RuntimeException('Resource type must be page or blog.');
            }
            $title = $this->plain((string) ($resource['pagetitle'] ?? $resource['title'] ?? ''));
            if ($title === '') {
                throw new RuntimeException('Every resource needs a title.');
            }
            $sourceUrl = (string) ($resource['source_url'] ?? $this->absoluteUrl((string) ($resource['uri'] ?? $resource['alias'] ?? ''), $this->sourceOrigin.'/'));
            $leaf = basename(trim((string) parse_url((string) ($resource['uri'] ?? $resource['alias'] ?? ''), PHP_URL_PATH), '/'));
            $base = (string) ($resource['local_slug'] ?? preg_replace('/\.(?:html?|php)$/i', '', $leaf));
            $slug = Str::slug($base !== '' ? $base : $title) ?: 'lavaggio-'.$id;
            if (isset($slugs[$type.'|'.$slug])) {
                $slug .= '-'.$id;
            }
            $slugs[$type.'|'.$slug] = $id;
            $code = 'lavaggio-modx-'.$id;
            $model = $type === 'blog' ? BlogPost::class : InfoPage::class;
            $collision = $model::query()->where('code', '!=', $code)
                ->whereHas('translations', fn ($query) => $query->where('locale', 'hr')->where('slug', $slug))->exists();
            if ($collision) {
                throw new RuntimeException('An unrelated local content item already uses the imported slug: '.$slug);
            }
            $url = $type === 'blog' ? '/blog/'.$slug : '/page/'.$slug;
            $override = $snapshot['url_overrides'][$id] ?? null;
            if (is_string($override) && str_starts_with($override, '/') && ! str_starts_with($override, '//')) {
                $url = $override;
            }
            $this->resourceUrls[$id] = $url;
            foreach ([$sourceUrl, (string) ($resource['uri'] ?? ''), (string) ($resource['alias'] ?? '')] as $oldUrl) {
                if ($oldUrl !== '') {
                    $this->pathUrls[$this->pathKey($oldUrl)] = $url;
                }
            }
            $resources[$id] = $resource + ['type' => $type];
            $resources[$id] = array_replace($resources[$id], compact('id', 'title', 'slug', 'code') + ['source_url' => $sourceUrl]);
        }
        foreach ((array) ($snapshot['source_resources'] ?? []) as $source) {
            if (! is_array($source) || ! isset($this->resourceUrls[(int) ($source['id'] ?? 0)])) {
                continue;
            }
            foreach (['source_url', 'uri', 'alias'] as $field) {
                if (isset($source[$field]) && (string) $source[$field] !== '') {
                    $this->pathUrls[$this->pathKey((string) $source[$field])] = $this->resourceUrls[(int) $source['id']];
                }
            }
        }
        // MODX may export the home resource as index.html while authored links
        // point at the bare public origin in either HTTP or HTTPS.
        $this->pathUrls['/'] = '/';

        return array_values($resources);
    }

    private function resourceBody(array $resource): string
    {
        foreach (['rendered_body_html', 'body_html', 'content'] as $field) {
            if (isset($resource[$field])) {
                return (string) $resource[$field];
            }
        }

        return '';
    }

    private function normalizeHtml(string $html, array $resource): string
    {
        $html = preg_replace_callback('/\[\[!?~(\d+)(?:\?[^\]]*)?\]\]/', function (array $match): string {
            $id = (int) $match[1];
            if (isset($this->inactiveResourceIds[$id])) {
                return $this->sourceOrigin.'/index.php?id='.$id;
            }
            if (isset($this->resourceUrls[$id])) {
                return $this->resourceUrls[$id];
            }
            $this->warnings[] = 'Unresolved MODX resource link ID '.$id.'.';

            return $this->sourceOrigin.'/index.php?id='.$id;
        }, $html) ?? $html;
        $values = array_merge($resource, (array) ($resource['tvs'] ?? []));
        $html = preg_replace_callback('/\[\[\*([a-zA-Z0-9_.-]+)\]\]/', fn (array $match): string => is_scalar($values[$match[1]] ?? null) ? (string) $values[$match[1]] : '', $html) ?? $html;
        $html = str_replace(['[[++site_url]]', '[[++assets_url]]'], [$this->sourceOrigin.'/', $this->sourceOrigin.'/assets/'], $html);
        for ($pass = 0; $pass < 8 && str_contains($html, '[['); $pass++) {
            $html = preg_replace('/\[\[(?:(?!\[\[|\]\]).)*\]\]/s', '', $html, -1, $count) ?? $html;
            $this->tagsRemoved += $count;
            if ($count === 0) {
                break;
            }
        }
        if (str_contains($html, '[[')) {
            throw new RuntimeException('Unresolved MODX markup remains in resource '.($resource['id'] ?? 'FAQ').'.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $encoded = mb_encode_numericentity('<div data-lavaggio-import-root="1">'.$html.'</div>', [0x80, 0x10FFFF, 0, 0xFFFFFF], 'UTF-8');
            $document->loadHTML($encoded, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
            $xpath = new DOMXPath($document);
            $root = $xpath->query('//*[@data-lavaggio-import-root="1"]')->item(0);
            if (! $root instanceof DOMElement) {
                throw new RuntimeException('The imported HTML could not be parsed.');
            }
            $elements = iterator_to_array($root->getElementsByTagName('*'));
            foreach (array_reverse($elements) as $element) {
                $tag = strtolower($element->tagName);
                if ($tag === 'a' && $this->isInactiveResourceLink($element->getAttribute('href'), $resource['source_url'])) {
                    $parent = $element->parentNode;
                    if ($parent) {
                        while ($element->firstChild) {
                            $parent->insertBefore($element->firstChild, $element);
                        }
                        $parent->removeChild($element);
                        $this->inactiveLinksRemoved++;
                    }

                    continue;
                }
                if (in_array($tag, ['script', 'style', 'link', 'meta', 'base', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'noscript', 'svg'], true)) {
                    $element->parentNode?->removeChild($element);

                    continue;
                }
                if ($tag === 'iframe') {
                    $video = $this->youtubeEmbed($element->getAttribute('src'));
                    if ($video === null) {
                        $this->warnings[] = 'Skipped an unsupported embedded frame in resource '.($resource['id'] ?? 'FAQ').'.';
                        $element->parentNode?->removeChild($element);

                        continue;
                    }
                    $width = (int) $element->getAttribute('width');
                    $height = (int) $element->getAttribute('height');
                    if ($width < 100 || $height < 100 || $width > 4096 || $height > 4096 || $width / $height > 4 || $height / $width > 4) {
                        $width = 960;
                        $height = 540;
                    }
                    foreach (iterator_to_array($element->attributes) as $attribute) {
                        $element->removeAttribute($attribute->name);
                    }
                    $element->setAttribute('src', $video);
                    $element->setAttribute('title', $this->plain((string) ($resource['title'] ?? 'Lavaggio video')));
                    $element->setAttribute('width', (string) $width);
                    $element->setAttribute('height', (string) $height);
                    $element->setAttribute('loading', 'lazy');
                    $element->setAttribute('allowfullscreen', '');
                    $element->setAttribute('class', 'lavaggio-content-video'.($width < $height ? ' lavaggio-content-video-portrait' : ''));

                    continue;
                }
                $background = null;
                $isSourceIntro = $tag === 'p' && in_array('lavaggio-content-intro', preg_split('/\s+/', $element->getAttribute('class')) ?: [], true);
                if (preg_match('/background(?:-image)?\s*:[^;]*url\(\s*["\x27]?([^"\x27)]+)["\x27]?\s*\)/i', $element->getAttribute('style'), $match)) {
                    $background = $this->downloadImage(trim($match[1]), $resource['source_url']);
                }
                $srcset = $element->getAttribute('srcset') ?: $element->getAttribute('data-srcset');
                $actualSrc = $element->getAttribute('data-src') ?: $element->getAttribute('data-lazy-src') ?: $element->getAttribute('src');
                foreach (iterator_to_array($element->attributes) as $attribute) {
                    if (! in_array(strtolower($attribute->name), ['href', 'src', 'alt', 'title', 'width', 'height', 'colspan', 'rowspan', 'scope', 'id', 'datetime', 'type', 'controls'], true)) {
                        $element->removeAttribute($attribute->name);
                    }
                }
                if ($isSourceIntro) {
                    $element->setAttribute('class', 'lavaggio-content-intro');
                }
                if ($element->hasAttribute('href')) {
                    $href = $this->localLink($element->getAttribute('href'), $resource['source_url']);
                    if ($href === null) {
                        $element->removeAttribute('href');
                    } else {
                        $element->setAttribute('href', $href);
                    }
                }
                if (in_array($tag, ['img', 'source'], true)) {
                    if ($actualSrc !== '') {
                        $local = $this->downloadImage($actualSrc, $resource['source_url']);
                        if ($local) {
                            $element->setAttribute('src', '/'.$local);
                        } else {
                            $element->removeAttribute('src');
                        }
                    }
                    if ($srcset !== '') {
                        $candidates = [];
                        foreach (explode(',', $srcset) as $candidate) {
                            if (preg_match('/^\s*(\S+)(?:\s+([0-9.]+[wx]))?\s*$/', $candidate, $match)) {
                                $local = $this->downloadImage($match[1], $resource['source_url']);
                                if ($local) {
                                    $candidates[] = '/'.$local.(! empty($match[2]) ? ' '.$match[2] : '');
                                }
                            }
                        }
                        if ($candidates !== []) {
                            $element->setAttribute('srcset', implode(', ', $candidates));
                        }
                    }
                    if ($tag === 'img') {
                        if (! $element->hasAttribute('src')) {
                            $element->parentNode?->removeChild($element);

                            continue;
                        }
                        $element->setAttribute('loading', 'lazy');
                        $element->setAttribute('decoding', 'async');
                    }
                } elseif ($element->hasAttribute('src')) {
                    // Executable media is admitted only by the YouTube branch above.
                    $element->removeAttribute('src');
                }
                if ($background) {
                    $image = $document->createElement('img');
                    $image->setAttribute('src', '/'.$background);
                    $image->setAttribute('alt', '');
                    $image->setAttribute('loading', 'lazy');
                    $image->setAttribute('decoding', 'async');
                    $element->insertBefore($image, $element->firstChild);
                }
            }
            foreach (iterator_to_array($xpath->query('.//comment()', $root)) as $comment) {
                $comment->parentNode?->removeChild($comment);
            }
            $body = '';
            foreach ($root->childNodes as $node) {
                $body .= $document->saveHTML($node);
            }

            return trim($body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function localLink(string $value, string $base): ?string
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($value === '' || str_starts_with($value, '#') || preg_match('/^(?:tel|mailto):/i', $value)) {
            return $value;
        }
        $url = $this->absoluteUrl($value, $base);
        if ($url === null) {
            return null;
        }
        if (! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::SOURCE_HOSTS, true)) {
            return $url;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $local = isset($query['id']) ? ($this->resourceUrls[(int) $query['id']] ?? null) : null;
        $local ??= $this->pathUrls[$this->pathKey($url)] ?? null;
        if ($local !== null) {
            $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);
            $this->linksRewritten++;

            return $local.($fragment !== '' ? '#'.$fragment : '');
        }
        if (str_starts_with($value, '/') && preg_match('~^/(?:page/|blog(?:/|$)|faq(?:[?#]|$)|shop(?:[?#]|$)|contact(?:[?#]|$))~', $value)) {
            return $value;
        }

        return $url;
    }

    private function isInactiveResourceLink(string $value, string $base): bool
    {
        $url = $this->absoluteUrl($value, $base);
        if ($url === null || ! in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), self::SOURCE_HOSTS, true)
            || ! in_array($this->pathKey($url), ['/', '/index.php'], true)) {
            return false;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return isset($query['id']) && is_scalar($query['id']) && isset($this->inactiveResourceIds[(int) $query['id']]);
    }

    private function downloadImage(string $reference, string $base): ?string
    {
        // MODX public documents use the site-root <base> for asset TV values.
        if (preg_match('~^(?:assets|images)/~i', $reference)) {
            $base = $this->sourceOrigin.'/';
        }
        $url = $this->absoluteUrl($reference, $base);
        if ($url === null || ! $this->allowedImageUrl($url)) {
            $this->warnings[] = 'Skipped an image outside the snapshot asset host allowlist.';

            return null;
        }
        if (array_key_exists($url, $this->assets)) {
            return $this->assets[$url];
        }
        $local = $this->localAssets[$url] ?? null;
        if (is_string($local) && preg_match('~\Aassets/lavaggio/content/[a-zA-Z0-9._/-]+\z~D', $local)
            && ! str_contains($local, '..') && is_file(public_path($local)) && filesize(public_path($local)) > 0) {
            return $this->assets[$url] = $local;
        }
        $prefix = hash('sha256', $url);
        foreach (['jpg', 'png', 'webp', 'avif', 'gif', 'svg'] as $extension) {
            $path = self::ASSET_DIRECTORY.'/'.$prefix.'.'.$extension;
            if (is_file(public_path($path)) && filesize(public_path($path)) > 0) {
                return $this->assets[$url] = $path;
            }
        }
        $fetchUrl = $url;
        for ($redirect = 0; $redirect < 4; $redirect++) {
            $response = Http::withOptions(['allow_redirects' => false])->timeout(30)->retry(2, 200, throw: false)->get($fetchUrl);
            if ($response->redirect()) {
                $next = $this->absoluteUrl((string) $response->header('Location'), $fetchUrl);
                if (! $next || ! $this->allowedImageUrl($next)) {
                    throw new RuntimeException('A source image redirected outside the allowed asset hosts.');
                }
                $fetchUrl = $next;

                continue;
            }
            if (! $response->successful()) {
                $this->warnings[] = 'Image unavailable (HTTP '.$response->status().'): '.$url;

                return $this->assets[$url] = null;
            }
            $bytes = $response->body();
            if (strlen($bytes) > 15 * 1024 * 1024) {
                throw new RuntimeException('A source image exceeds the 15 MiB import limit.');
            }
            $mime = @getimagesizefromstring($bytes)['mime'] ?? '';
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif'][$mime] ?? null;
            if ($extension === null && preg_match('/<svg\b/i', $bytes)
                && ! preg_match('/<!DOCTYPE|<!ENTITY|<\s*(?:script|foreignObject)\b|\son[a-z]+\s*=|javascript\s*:/i', $bytes)) {
                $extension = 'svg';
            }
            if ($extension === null) {
                throw new RuntimeException('A source image response is not a supported image: '.$url);
            }
            $bytes = $this->resizePhoto($bytes, $mime);
            $path = self::ASSET_DIRECTORY.'/'.$prefix.'.'.$extension;
            File::ensureDirectoryExists(public_path(self::ASSET_DIRECTORY));
            File::put(public_path($path), $bytes);

            return $this->assets[$url] = $path;
        }

        throw new RuntimeException('A source image exceeded the redirect limit.');
    }

    private function attachCover(BlogPost $post, string $path, string $source): void
    {
        $existing = $post->getFirstMedia('blog_cover');
        if ($existing && $existing->getCustomProperty('lavaggio_source_url') === $source && is_file($existing->getPath())) {
            return;
        }
        $mime = mime_content_type(public_path($path));
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
            $this->warnings[] = 'A blog cover has an unsupported media-library format: '.$source;

            return;
        }
        // The bounded local original serves every existing blog view. Avoid
        // generating eighteen portrait/crop variants for each archive item;
        // later admin uploads retain the normal configured conversion profile.
        $key = 'media_profiles.models.'.BlogPost::class.'.collections.blog_cover.conversions';
        $conversions = config($key, []);
        try {
            config([$key => []]);
            $post->addMedia(public_path($path))->preservingOriginal()
                ->withCustomProperties(['lavaggio_source_url' => $source, 'alt' => ['hr' => $post->translations()->where('locale', 'hr')->value('title')]])
                ->toMediaCollection('blog_cover');
        } finally {
            config([$key => $conversions]);
        }
    }

    private function importBlogCategories(BlogPost $post, array $resource, ?int $userId): void
    {
        $raw = $resource['blog_categories'] ?? $resource['tvs']['tag'] ?? [];
        $tags = array_values(array_unique(array_filter(array_map(
            fn (mixed $value): string => $this->plain(is_scalar($value) ? (string) $value : ''),
            is_array($raw) ? $raw : explode(',', (string) $raw)
        ))));
        $payload = (array) $post->payload;
        $applied = (array) ($payload['lavaggio_blog_categories'] ?? []);
        foreach ($tags as $name) {
            $slug = Str::slug($name);
            if ($slug === '') {
                continue;
            }
            $category = Category::query()->firstOrCreate([
                'scope' => Category::SCOPE_BLOG, 'code' => 'lavaggio-blog-'.$slug,
            ], [
                'is_active' => true, 'show_in_menu' => false, 'sort_order' => 0,
                'payload' => ['source' => 'lavaggio-modx', 'source_tag' => $name],
                'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $category->translations()->firstOrCreate(['locale' => 'hr'], [
                'scope' => Category::SCOPE_BLOG, 'name' => $name, 'slug' => $slug,
                'meta_title' => $name.' | TV studio Lavaggio',
            ]);
            $this->blogCategoryIds[(int) $category->id] = true;
            if (array_key_exists($name, $applied)) {
                continue;
            }
            if (! $post->categories()->whereKey($category->id)->exists()) {
                $post->categories()->attach($category->id, [
                    'sort_order' => count($applied), 'is_primary' => ! $post->categories()->exists(),
                ]);
            }
            // Re-imports retain category renames and manual category removals.
            $applied[$name] = (int) $category->id;
        }
        if ($tags !== []) {
            $payload['lavaggio_blog_categories'] = $applied;
            $post->update(['payload' => $payload]);
        }
    }

    private function allowedImageUrl(string $url): bool
    {
        $parts = parse_url($url);

        return $parts !== false && ! isset($parts['user']) && ! isset($parts['pass'])
            && in_array(strtolower((string) ($parts['host'] ?? '')), $this->assetHosts, true)
            && (! isset($parts['port']) || in_array($parts['port'], [80, 443], true));
    }

    private function absoluteUrl(string $value, string $base): ?string
    {
        try {
            $url = UriResolver::resolve(new Uri($base), new Uri(html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (! in_array(strtolower($url->getScheme()), ['http', 'https'], true) || $url->getUserInfo() !== '') {
                return null;
            }

            return (string) $url;
        } catch (\Throwable) {
            return null;
        }
    }

    private function pathKey(string $url): string
    {
        return '/'.trim(rawurldecode((string) parse_url($url, PHP_URL_PATH)), '/');
    }

    private function youtubeEmbed(string $url): ?string
    {
        $parts = parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url);
        if ($parts === false || ! in_array(strtolower((string) ($parts['host'] ?? '')), ['youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'], true)
            || ! preg_match('~^/embed/([A-Za-z0-9_-]{6,})/?$~', (string) ($parts['path'] ?? ''), $match)) {
            return null;
        }

        return 'https://www.youtube-nocookie.com/embed/'.$match[1];
    }

    private function sourceDate(mixed $value): ?CarbonImmutable
    {
        if (empty($value)) {
            return null;
        }

        return is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : CarbonImmutable::parse((string) $value);
    }

    private function resizePhoto(string $bytes, string $mime): string
    {
        $size = @getimagesizefromstring($bytes);
        if (! $size || max($size[0], $size[1]) <= 1800 || ! function_exists('imagecreatefromstring')
            || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $bytes;
        }
        $source = @imagecreatefromstring($bytes);
        if (! $source) {
            return $bytes;
        }
        $ratio = 1800 / max($size[0], $size[1]);
        $target = imagecreatetruecolor(max(1, (int) round($size[0] * $ratio)), max(1, (int) round($size[1] * $ratio)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $size[0], $size[1]);
        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($target, null, 88),
            'image/png' => imagepng($target, null, 7),
            'image/webp' => imagewebp($target, null, 88),
        };
        $resized = ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        return is_string($resized) && $resized !== '' ? $resized : $bytes;
    }

    private function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
    }
}
