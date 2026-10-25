<?php

namespace App\Services\Import;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Reference\ReferenceProject;
use App\Support\Media\WatchlineMediaReference;
use App\Support\WatchlineWidgetSettings;
use DOMDocument;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;

/** A public-content inventory only: no source database, customers, orders or HTTP. */
class WatchlineMediaManifestService
{
    private array $references = [];

    private array $issues = [];

    private array $aliases = [];

    private array $directoryEntries = [];

    private array $scanned = [];

    private array $retainedIds = [];

    public function inspect(?string $sourceRoot = null): array
    {
        if (config('storefront.home') !== 'watchline') {
            throw new InvalidArgumentException('This inventory is for Watchline only.');
        }
        if ($sourceRoot !== null && ($sourceRoot = realpath($sourceRoot)) === false) {
            throw new InvalidArgumentException('The optional original image root must be a readable local directory.');
        }
        if ($sourceRoot !== null && (! is_dir($sourceRoot) || ! is_readable($sourceRoot))) {
            throw new InvalidArgumentException('The optional original image root must be a readable local directory.');
        }
        $this->references = $this->issues = $this->aliases = $this->directoryEntries = $this->scanned = $this->retainedIds = [];
        $tables = [
            'products', 'product_translations', 'categories', 'category_translations', 'catalog_manufacturers', 'catalog_manufacturer_translations',
            'catalog_option_values', 'catalog_option_value_translations', 'catalog_bundles',
            'content_blocks', 'content_block_translations', 'content_blog_posts', 'content_blog_post_translations',
            'content_info_pages', 'content_info_page_translations', 'content_faqs', 'content_faq_translations',
            'content_reference_projects', 'content_reference_project_translations',
        ];
        foreach ($tables as $table) {
            $this->scanTable($table);
        }
        $this->scanSettings();
        $this->scanWidgetDefaults();
        $this->scanNativeMedia();

        $lists = ['opencart' => [], 'native_public' => [], 'git_public' => []];
        foreach ($this->references as $kind => $paths) {
            ksort($paths, SORT_STRING);
            foreach ($paths as $path => $contexts) {
                $root = match ($kind) {
                    'opencart' => $sourceRoot,
                    'native_public' => config('filesystems.disks.public.driver') === 'local' ? realpath((string) config('filesystems.disks.public.root')) ?: null : null,
                    default => realpath(public_path()) ?: null,
                };
                $state = $root === null ? ['state' => 'unverified', 'bytes' => null] : $this->fileState($root, $path);
                $lists[$kind][] = ['path' => $path, 'state' => $state['state'], 'bytes' => $state['bytes'], 'referenced_by' => array_keys($contexts)];
            }
        }
        ksort($this->aliases, SORT_STRING);
        $summary = [];
        foreach ($lists as $kind => $entries) {
            $summary[$kind] = ['paths' => count($entries), 'verified' => count(array_filter($entries, fn ($entry) => $entry['state'] === 'present')),
                'missing_or_unsafe' => count(array_filter($entries, fn ($entry) => ! in_array($entry['state'], ['present', 'unverified'], true))),
                'verified_bytes' => array_sum(array_column($entries, 'bytes'))];
        }

        return [
            'version' => 1, 'generated_at' => now()->toIso8601String(), 'database_mutated' => false, 'remote_requests' => false,
            'source_image_root' => $sourceRoot ?? '/home/watchline/public_html/image', 'source_root_verified' => $sourceRoot !== null,
            'scope' => 'All retained public catalog and content, including inactive records and all translations. Public image settings, effective Watchline widgets and public-model native images. No customers, orders, source archives, private attachments or OpenCart cache directory inventory.',
            'scanned_rows' => $this->scanned, 'summary' => $summary,
            'opencart_originals' => $lists['opencart'], 'native_public' => $lists['native_public'], 'git_public_assets' => $lists['git_public'],
            'cache_aliases' => array_values($this->aliases), 'excluded_or_rejected' => array_values($this->issues),
            'review_required' => $sourceRoot === null || $this->aliases !== [] || $this->issues !== [] || collect($summary)->sum('missing_or_unsafe') > 0,
            'notes' => ['files0 paths are relative to their named roots and never directories or wildcards.',
                'Without --source-root, originals are referenced but their existence/size is unverified. Validate on the source server before transfer.',
                'With --source-root, absent, wrong-case, empty and escaping/symlink files remain in JSON but are omitted from the source files0.',
                'Cache aliases require new-shop URL normalization or a separate reviewed compatibility step; no derived OpenCart cache files are copied.',
                'Git public assets are supplied by deployment. Native public files belong to storage/app/public, not the old OpenCart image root.'],
        ];
    }

    /** Exact NUL-separated files; explicit --from0/--files-from and a fixed source root are required. */
    public function fileList(array $manifest, string $kind = 'opencart_originals'): string
    {
        if (! in_array($kind, ['opencart_originals', 'native_public'], true)) {
            throw new InvalidArgumentException('Unknown transfer list.');
        }
        $paths = [];
        foreach ($manifest[$kind] ?? [] as $entry) {
            if (in_array($entry['state'] ?? '', ['present', 'unverified'], true)
                && WatchlineMediaReference::safePath($entry['path'] ?? '')
                && ! str_starts_with($entry['path'], 'cache/')) {
                $paths[$entry['path']] = true;
            }
        }
        $paths = array_keys($paths);
        sort($paths, SORT_STRING);

        return $paths === [] ? '' : implode("\0", $paths)."\0";
    }

    private function scanTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $columns = array_values(array_intersect(['payload', 'description', 'body_html', 'answer_html', 'excerpt', 'image', 'image_url', 'logo', 'banner'], Schema::getColumnListing($table)));
        $this->scanned[$table] = 0;
        foreach (DB::table($table)->select(['id', ...$columns])->orderBy('id')->cursor() as $row) {
            $this->scanned[$table]++;
            $this->retainedIds[$table][(int) $row->id] = true;
            foreach ($columns as $column) {
                $this->scanValue($column === 'payload' ? $this->decode($row->{$column}) : $row->{$column}, $table.'#'.$row->id.':'.$column);
            }
        }
    }

    private function scanSettings(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }
        $this->scanned['public_image_settings'] = 0;
        foreach (DB::table('system_settings')->select(['key', 'value'])->orderBy('id')->cursor() as $row) {
            if (preg_match('/password|secret|token|api_key|smtp|credential/i', $row->key)
                || ! preg_match('/^(?:front_|store_(?:brand_|seo_|footer_|header_|home_|.*(?:image|logo|favicon|banner|background)))/', $row->key)) {
                continue;
            }
            $this->scanned['public_image_settings']++;
            $value = $this->decode($row->value);
            // StoreSettingsService::assetUrl resolves uploaded relative brand/OG
            // paths against the public disk, unlike OpenCart's image directory.
            if (is_string($value) && $this->looksLikeImage($value)
                && preg_match('/^store_(?:brand_(?:logo|favicon)[a-z0-9_]*|og_[a-z0-9_]+_image)_path$/', $row->key)
                && ! str_starts_with($value, '/') && ! str_contains($value, '://') && ! str_starts_with($value, 'assets/brand/')) {
                $this->recordPath('native_public', $value, 'settings:'.$row->key);
            } else {
                $this->scanValue($value, 'settings:'.$row->key);
            }
        }
    }

    private function scanWidgetDefaults(): void
    {
        if (! Schema::hasTable('content_blocks')) {
            return;
        }
        foreach (ContentBlock::query()->with('translations')->lazyById(100) as $block) {
            foreach ($block->translations->isEmpty() ? [null] : $block->translations as $translation) {
                $this->scanValue(WatchlineWidgetSettings::forBlock($block, $translation), 'effective_widget#'.$block->id.':'.($translation?->locale ?? 'default'));
            }
        }
    }

    private function scanNativeMedia(): void
    {
        if (! Schema::hasTable('media')) {
            return;
        }
        $models = [Product::class, Category::class, Manufacturer::class, ContentBlock::class, BlogPost::class, InfoPage::class, ReferenceProject::class, Bundle::class];
        $aliases = array_keys(array_filter(Relation::morphMap(), fn ($class) => in_array($class, $models, true)));
        $this->scanned['public_model_native_images'] = 0;
        foreach (DB::table('media')->whereIn('model_type', [...$models, ...$aliases])->where('mime_type', 'like', 'image/%')->orderBy('id')->cursor() as $row) {
            $class = Relation::getMorphedModel($row->model_type) ?? $row->model_type;
            $table = (new $class)->getTable();
            if (! isset($this->retainedIds[$table][(int) $row->model_id])) {
                $this->scanned['orphan_native_images_skipped'] = ($this->scanned['orphan_native_images_skipped'] ?? 0) + 1;

                continue;
            }
            $this->scanned['public_model_native_images']++;
            $media = new Media;
            $media->setRawAttributes((array) $row, true);
            if ($media->disk !== 'public' || config('filesystems.disks.public.driver') !== 'local') {
                $this->issue('excluded', 'native_image_on_other_disk', 'media#'.$row->id, (string) $row->disk);

                continue;
            }
            $generator = PathGeneratorFactory::create($media);
            $this->recordPath('native_public', $generator->getPath($media).$media->file_name, 'media#'.$row->id.':original');
            foreach ((array) $media->generated_conversions as $conversion => $generated) {
                if (! $generated) {
                    continue;
                }
                if (($media->conversions_disk ?: $media->disk) !== 'public') {
                    $this->issue('excluded', 'native_derivative_on_other_disk', 'media#'.$row->id, (string) $conversion);

                    continue;
                }
                try {
                    $this->recordPath('native_public', $media->getPathRelativeToRoot($conversion), 'media#'.$row->id.':conversion:'.$conversion);
                } catch (\Throwable) {
                    $this->issue('excluded', 'unregistered_native_conversion', 'media#'.$row->id, (string) $conversion);
                }
            }
            foreach ((array) $media->responsive_images as $conversion => $responsive) {
                if (! is_array($responsive)) {
                    $this->issue('rejected', 'invalid_native_responsive_metadata', 'media#'.$row->id, (string) $conversion);

                    continue;
                }
                if ($conversion !== 'media_library_original' && ($media->conversions_disk ?: $media->disk) !== 'public') {
                    continue;
                }
                foreach ((array) ($responsive['urls'] ?? []) as $filename) {
                    if (is_string($filename)) {
                        $this->recordPath('native_public', $generator->getPathForResponsiveImages($media).$filename, 'media#'.$row->id.':responsive');
                    }
                }
            }
        }
    }

    private function scanValue(mixed $value, string $context): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->scanValue($item, $context.'.'.$key);
            }
        } elseif (is_string($value) && trim($value) !== '') {
            if (str_contains($value, '<')) {
                $previous = libxml_use_internal_errors(true);
                try {
                    $document = new DOMDocument;
                    $document->loadHTML('<?xml encoding="UTF-8">'.$value, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                    foreach ($document->getElementsByTagName('*') as $node) {
                        $attributes = match ($node->tagName) {
                            'img', 'source' => ['src', 'data-src', 'data-original', 'srcset', 'data-srcset'],
                            'video' => ['poster'],
                            default => [],
                        };
                        foreach ($attributes as $attribute) {
                            if ($node->hasAttribute($attribute)) {
                                foreach (str_contains($attribute, 'srcset') ? $this->srcset($node->getAttribute($attribute)) : [$node->getAttribute($attribute)] as $reference) {
                                    $this->record($reference, $context.':'.$node->tagName.'['.$attribute.']');
                                }
                            }
                        }
                        if ($node->tagName === 'a' && $this->looksLikeImage($node->getAttribute('href'))) {
                            $this->record($node->getAttribute('href'), $context.':a[href]');
                        }
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            } elseif (preg_match('/(?:^|[.:])(?:data-)?srcset$/', $context)) {
                foreach ($this->srcset($value) as $reference) {
                    $this->record($reference, $context);
                }
            } elseif ($this->looksLikeImage($value)) {
                $this->record($value, $context, (bool) preg_match('/(?:\.opencart\.image|\.gallery_images\.[0-9]+(?:\.image)?)$/', $context));
            }
            if (preg_match_all('/url\(\s*(?:"([^"]*)"|\x27([^\x27]*)\x27|([^\)\s]*))\s*\)/i', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $this->record($match[1] ?: ($match[2] ?: ($match[3] ?? '')), $context.':css-url');
                }
            }
        }
    }

    private function record(string $reference, string $context, bool $legacyField = false): void
    {
        if (trim($reference) === '') {
            return;
        }
        $result = WatchlineMediaReference::classify($reference, $legacyField);
        if (isset($result['path'])) {
            $this->recordPath($result['kind'], $result['path'], $context);
            if (isset($result['cache_path'])) {
                $this->aliases[$result['cache_path']] = ['cache_path' => $result['cache_path'], 'original_path' => $result['path'], 'new_shop_url_update_required' => true];
            }
        } else {
            $this->issue($result['kind'], $result['reason'], $context, $reference, $result['host'] ?? null);
        }
    }

    private function recordPath(string $kind, string $path, string $context): void
    {
        if (! WatchlineMediaReference::safePath($path)) {
            $this->issue('rejected', 'unsafe_or_non_image_path', $context, $path);

            return;
        }
        $this->references[$kind][$path][$context] = true;
    }

    private function issue(string $kind, string $reason, string $context, string $reference, ?string $host = null): void
    {
        $key = hash('sha256', $kind.$reason.$context.$reference);
        $this->issues[$key] = array_filter(['kind' => $kind, 'reason' => $reason, 'context' => $context, 'reference_sha256' => hash('sha256', $reference), 'host' => $host], fn ($value) => $value !== null);
    }

    private function fileState(string $root, string $path): array
    {
        $cursor = $root;
        foreach (explode('/', $path) as $segment) {
            $this->directoryEntries[$cursor] ??= array_fill_keys(@scandir($cursor) ?: [], true);
            if (! isset($this->directoryEntries[$cursor][$segment])) {
                return ['state' => 'missing_or_wrong_case', 'bytes' => null];
            }
            $cursor .= '/'.$segment;
            if (is_link($cursor)) {
                return ['state' => 'symlink_rejected', 'bytes' => null];
            }
        }
        $real = realpath($cursor);
        if ($real === false || ! str_starts_with($real, $root.'/') || ! is_file($real) || ! is_readable($real) || filesize($real) === 0) {
            return ['state' => 'missing_empty_or_outside_root', 'bytes' => null];
        }

        return ['state' => 'present', 'bytes' => filesize($real)];
    }

    private function decode(mixed $value): mixed
    {
        return is_string($value) ? (json_decode($value, true) ?? $value) : $value;
    }

    private function looksLikeImage(string $value): bool
    {
        return (bool) preg_match('/\.(?:'.implode('|', WatchlineMediaReference::EXTENSIONS).')(?:[?#][^\s<>]*)?\s*$/i', $value);
    }

    private function srcset(string $value): array
    {
        return array_map(fn ($item) => preg_replace('/\s+[0-9]+(?:\.[0-9]+)?[wx]\s*$/', '', trim($item)), explode(',', $value));
    }
}
