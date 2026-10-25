<?php

namespace App\Support\Media;

/** Classifies public image references without reading files or making requests. */
final class WatchlineMediaReference
{
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico'];

    public static function classify(string $reference, bool $legacyField = false): array
    {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $reference)) {
            return ['kind' => 'rejected', 'reason' => 'control_character_or_backslash'];
        }
        if (str_starts_with(strtolower($reference), 'data:')) {
            return ['kind' => 'excluded', 'reason' => 'inline_data'];
        }
        $url = parse_url(str_starts_with($reference, '//') ? 'https:'.$reference : $reference);
        if ($url === false || isset($url['user']) || isset($url['pass'])) {
            return ['kind' => 'rejected', 'reason' => 'invalid_or_authenticated_url'];
        }
        if (isset($url['scheme']) && ! in_array(strtolower($url['scheme']), ['http', 'https'], true)) {
            return ['kind' => 'rejected', 'reason' => 'unsupported_scheme'];
        }
        if (isset($url['host'])) {
            $hosts = array_filter(['watchline.hr', 'www.watchline.hr', parse_url((string) config('app.url'), PHP_URL_HOST)]);
            if (! in_array(strtolower($url['host']), array_map('strtolower', $hosts), true)) {
                return ['kind' => 'excluded', 'reason' => 'unrelated_external', 'host' => strtolower($url['host'])];
            }
            if (isset($url['port']) && $url['port'] !== (strtolower($url['scheme'] ?? 'https') === 'https' ? 443 : 80)) {
                return ['kind' => 'rejected', 'reason' => 'unexpected_port'];
            }
        }
        $path = rawurldecode((string) ($url['path'] ?? ''));
        if (! self::safePath(ltrim($path, '/'))) {
            return ['kind' => 'rejected', 'reason' => 'unsafe_or_non_image_path'];
        }
        $path = ltrim($path, '/');
        $original = LegacyCatalogImage::relativePath($reference);
        if ($original !== null) {
            return ['kind' => 'opencart', 'path' => $original];
        }
        if (str_starts_with($path, 'storage/')) {
            return ['kind' => 'native_public', 'path' => substr($path, 8)];
        }
        foreach (['assets/', 'front-theme/', 'build/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return ['kind' => 'git_public', 'path' => $path];
            }
        }
        $explicitImageRoot = str_starts_with($path, 'image/');
        if ($explicitImageRoot) {
            $path = substr($path, 6);
        }
        if (! $explicitImageRoot && (! $legacyField || str_starts_with((string) ($url['path'] ?? ''), '/'))
            && ! str_starts_with($path, 'catalog/') && ! str_starts_with($path, 'cache/')) {
            return ['kind' => 'excluded', 'reason' => 'outside_opencart_image_root'];
        }
        if (! self::safePath($path)) {
            return ['kind' => 'rejected', 'reason' => 'unsafe_or_non_image_path'];
        }
        if (str_starts_with($path, 'cache/')) {
            // Exact inverse of this shop's catalog/model/tool/image.php resize().
            $original = preg_replace('/-[0-9]+x[0-9]+(?=\.[^.\/]+$)/D', '', substr($path, 6), 1, $replaced);
            if ($replaced !== 1 || ! self::safePath($original) || str_starts_with($original, 'cache/')) {
                return ['kind' => 'rejected', 'reason' => 'unrecoverable_cache_reference'];
            }

            return ['kind' => 'opencart', 'path' => $original, 'cache_path' => $path];
        }

        return ['kind' => 'opencart', 'path' => $path];
    }

    public static function safePath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/') && ! str_starts_with($path, '-')
            && ! preg_match('/[\x00-\x1f\x7f\\\\?#:%]/', $path)
            && count(array_intersect(explode('/', $path), ['', '.', '..'])) === 0
            && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
