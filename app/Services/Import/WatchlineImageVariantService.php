<?php

namespace App\Services\Import;

use App\Models\Catalog\Product\Product;
use App\Support\Media\LegacyCatalogImage;
use RuntimeException;

/** Local, offline-only encoding. HTTP lookups only read existing index/files. */
class WatchlineImageVariantService
{
    public const WIDTHS = [160, 480, 960];

    private static array $indexes = [];

    public function variant(string $relative, int $width): ?array
    {
        if (config('storefront.home') !== 'watchline' || ! config('legacy_media.variants_enabled', false) || ! in_array($width, self::WIDTHS, true)) {
            return null;
        }
        $source = $this->source($relative);
        $index = $this->index();
        $entry = is_array($index['images'] ?? null) ? ($index['images'][$relative] ?? []) : [];
        $variant = is_array($entry) && is_array($entry['variants'] ?? null) ? ($entry['variants'][$width] ?? []) : [];
        if (! is_array($entry) || ! is_array($variant)) {
            return null;
        }
        if ($source === null || ($index['version'] ?? '') !== $this->version()
            || ($entry['bytes'] ?? null) !== $source['bytes'] || ($entry['mtime'] ?? null) !== $source['mtime']
            || ! preg_match('/\A[a-f0-9]{64}-w[1-9][0-9]*\.webp\z/D', (string) ($variant['file'] ?? ''))
            || (int) ($variant['width'] ?? 0) < 1 || (int) $variant['width'] > $width || (int) ($variant['height'] ?? 0) < 1) {
            return null;
        }
        $cache = $source['root'].'/watchline-variants';
        $file = $cache.'/'.$variant['file'];
        if (is_link($cache) || is_link($file) || ! is_file($file) || filesize($file) === 0) {
            return null;
        }

        return ['url' => asset('image/watchline-variants/'.$variant['file']), 'width' => (int) $variant['width'], 'height' => (int) $variant['height']];
    }

    /** Read all candidates once; at most two existing unique originals per active product. */
    public function select(int $limit = 200, array $productIds = []): array
    {
        if (config('storefront.home') !== 'watchline' || $limit < 0 || $limit > 10000) {
            throw new RuntimeException('Use a Watchline catalog and a limit from 0 to 10000.');
        }
        $query = Product::query()->where('is_active', true)->select(['id', 'payload'])->orderBy('id');
        if ($productIds !== []) {
            $query->whereIn('id', $productIds);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }
        $paths = [];
        $products = 0;
        $unavailable = 0;
        foreach ($query->cursor() as $product) {
            $products++;
            $gallery = data_get($product->payload, 'opencart.gallery_images', []);
            $candidates = [data_get($product->payload, 'opencart.image'), ...collect(is_array($gallery) ? $gallery : [])
                ->sortBy(fn ($item) => is_array($item) ? ($item['sort_order'] ?? 0) : 0)
                ->map(fn ($item) => is_array($item) ? ($item['image'] ?? null) : $item)->all()];
            $chosen = [];
            foreach ($candidates as $candidate) {
                $relative = LegacyCatalogImage::relativePath($candidate);
                if ($relative === null || $this->source($relative) === null) {
                    $unavailable += (int) (is_string($candidate) && $candidate !== '');

                    continue;
                }
                $chosen[$relative] = true;
                if (count($chosen) === 2) {
                    break;
                }
            }
            $paths += $chosen;
        }

        return ['products' => $products, 'unavailable_references' => $unavailable, 'paths' => array_keys($paths)];
    }

    /** Default is a metadata-only plan. No business records or originals are ever written. */
    public function process(array $paths, bool $apply = false): array
    {
        if (config('storefront.home') !== 'watchline' || count($paths) > 20000 || count(array_filter($paths, 'is_string')) !== count($paths)) {
            throw new RuntimeException('A bounded Watchline image selection is required.');
        }
        $report = ['dry_run' => ! $apply, 'selected_paths' => count(array_unique($paths)), 'ready_sources' => 0,
            'source_bytes' => 0, 'planned_files' => 0, 'generated_files' => 0, 'reused_files' => 0, 'output_bytes' => 0,
            'skipped' => [], 'database_mutated' => false, 'remote_requests' => false];
        $lock = null;
        if ($apply) {
            if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
                throw new RuntimeException('GD with WebP encoding is required.');
            }
            $this->ensureDirectory($this->privateRoot(), storage_path(), 0700);
            if (is_link($this->privateRoot().'/.lock')) {
                throw new RuntimeException('Unsafe image cache lock.');
            }
            $lock = fopen($this->privateRoot().'/.lock', 'c');
            if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                throw new RuntimeException('Another image preparation is running.');
            }
            chmod($this->privateRoot().'/.lock', 0600);
        }
        try {
            $index = $this->index(true);
            if (($index['version'] ?? '') !== $this->version() || ! is_array($index['images'] ?? null)) {
                $index = ['version' => $this->version(), 'images' => []];
            }
            foreach (array_unique($paths) as $relative) {
                $source = is_string($relative) ? $this->source($relative) : null;
                $info = $source ? $this->imageInfo($source) : null;
                if ($info === null) {
                    $report['skipped']['missing_unsafe_unsupported_or_oversized'] = ($report['skipped']['missing_unsafe_unsupported_or_oversized'] ?? 0) + 1;

                    continue;
                }
                $report['ready_sources']++;
                $report['source_bytes'] += $source['bytes'];
                $report['planned_files'] += count(array_unique(array_map(fn ($width) => min($width, $info['width']), self::WIDTHS)));
                if (! $apply) {
                    continue;
                }
                $cache = $source['root'].'/watchline-variants';
                $this->ensureDirectory($cache, $source['root'], 0755);
                $hash = hash_file('sha256', $source['file']);
                $key = hash('sha256', $this->version()."\0".$relative."\0".$hash);
                $variants = [];
                $doneWidths = [];
                $image = null;
                try {
                    foreach (self::WIDTHS as $requested) {
                        $width = min($requested, $info['width']);
                        $height = max(1, (int) round($info['height'] * $width / $info['width']));
                        $filename = $key.'-w'.$width.'.webp';
                        $target = $cache.'/'.$filename;
                        if (! isset($doneWidths[$width])) {
                            if ($this->validWebp($target, $width, $height)) {
                                $report['reused_files']++;
                            } else {
                                $image ??= $this->decodeImage($source['file'], $info['orientation']);
                                $this->encode($image, $target, $width, $height);
                                $report['generated_files']++;
                            }
                            $report['output_bytes'] += filesize($target);
                            $doneWidths[$width] = true;
                        }
                        $variants[$requested] = ['file' => $filename, 'width' => $width, 'height' => $height];
                    }
                } catch (\Throwable) {
                    $report['skipped']['encoding_failed'] = ($report['skipped']['encoding_failed'] ?? 0) + 1;

                    continue;
                } finally {
                    if ($image !== null) {
                        imagedestroy($image);
                    }
                }
                clearstatcache(true, $source['file']);
                if ($source['bytes'] !== filesize($source['file']) || $source['mtime'] !== filemtime($source['file'])
                    || $hash !== hash_file('sha256', $source['file'])) {
                    $report['skipped']['source_changed_during_encoding'] = ($report['skipped']['source_changed_during_encoding'] ?? 0) + 1;

                    continue;
                }
                $index['images'][$relative] = ['bytes' => $source['bytes'], 'mtime' => $source['mtime'], 'sha256' => $hash, 'variants' => $variants];
            }
            if ($apply) {
                $this->saveIndex($index);
            }
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $report;
    }

    private function source(string $relative): ?array
    {
        if (LegacyCatalogImage::relativePath($relative) !== $relative) {
            return null;
        }
        $configuredRoot = rtrim((string) config('legacy_media.local_root', public_path('image')), '/');
        $root = realpath($configuredRoot);
        $public = realpath(public_path());
        if ($root === false || is_link($configuredRoot)
            || (! app()->environment('testing') && ($public === false || is_link(public_path('image')) || $root !== $public.'/image'))) {
            return null;
        }
        $file = $root;
        foreach (explode('/', $relative) as $segment) {
            $file .= '/'.$segment;
            if (is_link($file)) {
                return null;
            }
        }
        $real = realpath($file);

        return $real !== false && $real === $root.'/'.$relative && is_file($real) && is_readable($real) && filesize($real) > 0
            ? ['root' => $root, 'file' => $real, 'bytes' => filesize($real), 'mtime' => filemtime($real)] : null;
    }

    private function imageInfo(array $source): ?array
    {
        if ($source['bytes'] > 25 * 1024 * 1024 || ! ($info = @getimagesize($source['file']))
            || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_AVIF], true)
            || min($info[0], $info[1]) < 1 || max($info[0], $info[1]) > 12000 || $info[0] * $info[1] > 24000000) {
            return null;
        }
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        if ($memoryLimit > 0 && $memoryLimit - memory_get_usage(true) < $info[0] * $info[1] * 16 + $source['bytes'] + 32 * 1024 * 1024) {
            return null;
        }
        $orientation = $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') ? (int) (@exif_read_data($source['file'])['Orientation'] ?? 1) : 1;

        return ['width' => $orientation >= 5 && $orientation <= 8 ? $info[1] : $info[0],
            'height' => $orientation >= 5 && $orientation <= 8 ? $info[0] : $info[1], 'orientation' => $orientation];
    }

    private function decodeImage(string $file, int $orientation): \GdImage
    {
        $image = @imagecreatefromstring(file_get_contents($file));
        if (! $image) {
            throw new RuntimeException('The selected image could not be decoded.');
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180, 6 => -90, 5, 7, 8 => 90, default => 0
        };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            imagedestroy($image);
            $image = $rotated;
        }

        return $image;
    }

    private function encode(\GdImage $source, string $target, int $width, int $height): void
    {
        if (is_link($target)) {
            throw new RuntimeException('Unsafe image cache target.');
        }
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        $temporary = tempnam(dirname($target), '.variant-');
        try {
            $encoded = imagewebp($image, $temporary, $this->quality());
            clearstatcache(true, $temporary);
            if (! $encoded || ! $this->validWebp($temporary, $width, $height)) {
                throw new RuntimeException('The selected image could not be encoded.');
            }
            chmod($temporary, 0644);
            if (! rename($temporary, $target)) {
                throw new RuntimeException('The selected image cache could not be published.');
            }
        } finally {
            imagedestroy($image);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        clearstatcache(true, $target);
    }

    private function validWebp(string $file, int $width, int $height): bool
    {
        $info = ! is_link($file) && is_file($file) && filesize($file) > 0 ? @getimagesize($file) : false;

        return $info !== false && $info[2] === IMAGETYPE_WEBP && $info[0] === $width && $info[1] === $height;
    }

    private function index(bool $fresh = false): array
    {
        $file = $this->privateRoot().'/index.json';
        if ($this->unsafePrivatePath($file) || ! is_file($file) || filesize($file) > 16 * 1024 * 1024) {
            return [];
        }
        $key = $file.':'.filemtime($file).':'.filesize($file);
        if ($fresh || ! isset(self::$indexes[$key])) {
            $value = json_decode(file_get_contents($file), true);
            self::$indexes[$key] = is_array($value) ? $value : [];
        }

        return self::$indexes[$key];
    }

    private function saveIndex(array $index): void
    {
        $file = $this->privateRoot().'/index.json';
        if (is_link($file)) {
            throw new RuntimeException('Unsafe image cache index.');
        }
        $temporary = tempnam($this->privateRoot(), '.index-');
        try {
            if (file_put_contents($temporary, json_encode($index, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
                throw new RuntimeException('Image cache index could not be saved.');
            }
            chmod($temporary, 0600);
            if (! rename($temporary, $file)) {
                throw new RuntimeException('Image cache index could not be published.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        clearstatcache(true, $file);
        self::$indexes = [];
    }

    private function ensureDirectory(string $path, string $boundary, int $mode): void
    {
        $boundary = realpath($boundary);
        if ($boundary === false || ! str_starts_with($path, $boundary.'/')) {
            throw new RuntimeException('Unsafe image cache directory.');
        }
        $cursor = $boundary;
        foreach (explode('/', substr($path, strlen($boundary) + 1)) as $segment) {
            $cursor .= '/'.$segment;
            if ($segment === '' || in_array($segment, ['.', '..'], true) || is_link($cursor) || (file_exists($cursor) && ! is_dir($cursor))) {
                throw new RuntimeException('Unsafe image cache directory.');
            }
            if (! is_dir($cursor) && ! mkdir($cursor, $mode)) {
                throw new RuntimeException('Image cache directory could not be created.');
            }
        }
        chmod($path, $mode);
    }

    private function privateRoot(): string
    {
        return (string) config('legacy_media.variants_index_root', storage_path('app/private/watchline/image-variants'));
    }

    private function unsafePrivatePath(string $path): bool
    {
        $root = realpath(storage_path());
        if ($root === false || ! str_starts_with($path, $root.'/')) {
            return true;
        }
        $cursor = $root;
        foreach (explode('/', substr($path, strlen($root) + 1)) as $segment) {
            $cursor .= '/'.$segment;
            if (is_link($cursor)) {
                return true;
            }
        }

        return false;
    }

    private function version(): string
    {
        return 'v1-q'.$this->quality();
    }

    private function quality(): int
    {
        return max(1, min(100, (int) config('legacy_media.variants_quality', 82)));
    }
}
