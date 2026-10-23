<?php

namespace App\Services\Catalog;

use App\Models\Catalog\Attribute\Attribute;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Read-only facets over the original canonical Watchline attributes. */
class WatchlineCatalogFacets
{
    public const REVISION_KEY = 'watchline:catalog-facets:revision';

    public const FACETS = [
        'case-color' => ['label' => 'Boja kućišta', 'en' => 'Case colour', 'groups' => ['herrera-oc-attribute-38']],
        'water-resistance' => ['label' => 'Vodootpornost', 'en' => 'Water resistance', 'groups' => ['herrera-oc-attribute-15']],
        'case-diameter' => ['label' => 'Promjer kućišta', 'en' => 'Case diameter', 'groups' => ['herrera-oc-attribute-17']],
        'movement' => ['label' => 'Mehanizam', 'en' => 'Movement', 'groups' => ['herrera-oc-attribute-19']],
        // OpenCart stored the same fact in separate watch, glasses and jewellery groups.
        'collection' => ['label' => 'Kolekcija', 'en' => 'Collection', 'groups' => ['herrera-oc-attribute-21', 'herrera-oc-attribute-26', 'herrera-oc-attribute-45']],
    ];

    public static function enabled(): bool
    {
        return config('storefront.home') === 'watchline';
    }

    public static function sourceGroupCodes(): array
    {
        return array_merge(...array_column(self::FACETS, 'groups'));
    }

    /** Canonical values only: no description parsing, inferred facts or product writes. */
    public function definitions(string $locale, string $fallbackLocale): array
    {
        $key = 'watchline:catalog-facets:definitions:'.$locale.':'.$fallbackLocale.':'.Cache::get(self::REVISION_KEY, 'initial');

        return Cache::remember($key, 600, function () use ($locale, $fallbackLocale): array {
            $attributes = Attribute::query()->where('is_active', true)
                ->whereIn('group_code', self::sourceGroupCodes())
                ->with(['translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale])])
                ->orderBy('id')->get();
            $filters = [];
            foreach (self::FACETS as $code => $definition) {
                $values = [];
                foreach ($attributes->whereIn('group_code', $definition['groups']) as $attribute) {
                    $translation = $attribute->translations->firstWhere('locale', $locale)
                        ?? $attribute->translations->firstWhere('locale', $fallbackLocale);
                    $label = $this->plainValue((string) ($translation?->name ?? ''));
                    if ($label === '') {
                        continue;
                    }
                    $identity = Str::lower($label);
                    $values[$identity] ??= ['id' => (int) $attribute->id, 'label' => $label, 'attribute_ids' => []];
                    $values[$identity]['attribute_ids'][] = (int) $attribute->id;
                }
                if ($values === []) {
                    continue;
                }
                uasort($values, fn (array $left, array $right): int => strnatcasecmp($left['label'], $right['label']));
                $filters[] = [
                    'label' => str_starts_with($locale, 'hr') ? $definition['label'] : $definition['en'],
                    'query_key' => 'attr_'.Str::slug('watchline-'.$code, '_'),
                    'group_code' => 'watchline-'.$code,
                    'source_group_codes' => $definition['groups'],
                    'values' => array_values($values),
                ];
            }

            return $filters;
        });
    }

    public static function selectedIds(array $filter, int $selectedId): array
    {
        $value = collect($filter['values'] ?? [])->first(fn (array $value): bool => (int) $value['id'] === $selectedId);

        return $value ? array_map('intval', $value['attribute_ids'] ?? [$selectedId])
            : (isset($filter['source_group_codes']) ? [0] : [$selectedId]);
    }

    /** Map equivalent original IDs to one stable public choice in SQL aggregates. */
    public static function valueIdExpression(array $filters, string $column): string
    {
        $cases = [];
        foreach ($filters as $filter) {
            foreach ($filter['values'] ?? [] as $value) {
                $id = (int) $value['id'];
                $aliases = array_values(array_diff(array_map('intval', $value['attribute_ids'] ?? []), [$id]));
                if ($aliases !== []) {
                    $cases[] = 'WHEN '.$column.' IN ('.implode(',', $aliases).') THEN '.$id;
                }
            }
        }

        return $cases === [] ? $column : 'CASE '.implode(' ', $cases).' ELSE '.$column.' END';
    }

    /** Invalidate on actual writes, including pivot syncs that bypass model observers. */
    public static function onQueryExecuted(QueryExecuted $query): void
    {
        if (! self::enabled() || ! preg_match('/^\s*(?:insert(?:\s+or\s+\w+|\s+ignore)?\s+into|replace(?:\s+into)?|update|delete\s+from)\s+[`"]?([a-zA-Z0-9_]+)/i', $query->sql, $match)) {
            return;
        }
        $table = strtolower($match[1]);
        $prefix = strtolower($query->connection->getTablePrefix());
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            $table = substr($table, strlen($prefix));
        }
        if (! in_array($table, ['products', 'categories', 'category_product', 'catalog_attributes',
            'catalog_attribute_translations', 'catalog_attribute_product', 'catalog_product_option_values'], true)) {
            return;
        }
        $invalidate = static fn () => Cache::forever(self::REVISION_KEY, Str::random(16));
        $invalidate();
        if ($query->connection->transactionLevel() > 0) {
            $query->connection->afterCommit($invalidate);
        }
    }

    private function plainValue(string $value): string
    {
        for ($pass = 0; $pass < 4; $pass++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }
        $value = preg_replace('~</?[a-z][^>]*>~iu', '', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $value)) ?? $value);
        $value = preg_replace('/(?<=\d)\s*(mm|cm)\b/iu', ' $1', $value) ?? $value;
        $value = preg_replace('/\s*([\/,])\s*/u', '$1 ', $value) ?? $value;

        return trim($value);
    }
}
