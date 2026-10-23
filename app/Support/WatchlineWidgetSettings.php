<?php

namespace App\Support;

use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockTranslation;

class WatchlineWidgetSettings
{
    public static function defaults(string $type): array
    {
        return array_replace(
            (array) config('watchline_widgets.'.$type.'.content_defaults', []),
            array_map(static fn (array $field): mixed => $field['default'] ?? '', self::fields($type)),
        );
    }

    public static function fields(string $type): array
    {
        return (array) config('watchline_widgets.'.$type.'.fields', []);
    }

    public static function forBlock(ContentBlock $block, ?ContentBlockTranslation $translation): array
    {
        $type = (string) $block->type;
        if (self::fields($type) === []) {
            $type = match ((string) $block->code) {
                'watchline-home-campaigns' => 'watchline_campaigns',
                'watchline-home-locations' => 'watchline_locations',
                'watchline-home-assurance' => 'watchline_assurance',
                'watchline-home-benefits' => 'watchline_benefits',
                default => $type,
            };
        }

        $settings = array_replace(
            self::defaults($type),
            (array) data_get($block->payload, 'watchline', []),
            (array) data_get($translation?->payload, 'watchline', []),
        );
        foreach (['title', 'subtitle', 'cta_label', 'cta_url'] as $key) {
            if ($translation?->{$key} !== null) {
                $settings[$key] = (string) $translation->{$key};
            }
        }

        foreach (self::fields($type) as $key => $field) {
            $value = $settings[$key] ?? $field['default'] ?? '';
            $settings[$key] = match ($field['type'] ?? 'text') {
                'categories', 'manufacturers' => collect(is_array($value) ? $value : [])
                    ->filter(static fn ($id): bool => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0)
                    ->map(static fn ($id): int => (int) $id)->unique()->values()->all(),
                'image', 'url' => self::safeUrl($value, ($field['type'] ?? '') === 'image'),
                'icon' => is_string($value) && preg_match('/^[a-z0-9-]{1,80}$/', $value) ? $value : (string) ($field['default'] ?? ''),
                'checkbox' => filter_var($value, FILTER_VALIDATE_BOOL),
                'number' => max((int) ($field['min'] ?? 1), min((int) ($field['max'] ?? 50), (int) $value)),
                'select' => array_key_exists((string) $value, (array) ($field['options'] ?? [])) ? (string) $value : ($field['default'] ?? ''),
                default => is_scalar($value) ? (string) $value : (string) ($field['default'] ?? ''),
            };
        }
        if (isset($settings['cta_url'])) {
            $settings['cta_url'] = self::safeUrl($settings['cta_url']);
        }

        return $settings;
    }

    public static function safeUrl(mixed $value, bool $image = false): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return '';
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }
        if (! $image && str_starts_with($value, '#')) {
            return $value;
        }
        $url = parse_url($value);
        if ($url === false) {
            return '';
        }
        $scheme = strtolower((string) ($url['scheme'] ?? ''));
        if ($scheme === 'https' && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass'])) {
            return $value;
        }
        if (! $image && in_array($scheme, ['tel', 'mailto'], true)) {
            return $value;
        }

        return '';
    }
}
