<?php

namespace App\Services\Import;

use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Settings\SystemSettingsService;
use App\Support\Media\MediaUrl;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Only the reviewed public settings and eight named homepage blocks are owned here. */
class LavaggioPresentationSyncService
{
    public const SNAPSHOT = 'database/data/lavaggio-approved-presentation-20261104.json';

    private const CODES = ['lavaggio-home-campaigns', 'lavaggio-home-problems', 'lavaggio-home-categories',
        'lavaggio-home-latest', 'lavaggio-home-brands', 'lavaggio-home-promotions', 'lavaggio-home-reviews', 'lavaggio-home-blog'];

    public function plan(): array
    {
        if (config('storefront.home') !== 'lavaggio') {
            throw new RuntimeException('Presentation sync requires the Lavaggio storefront.');
        }
        $manifest = json_decode(file_get_contents(base_path(self::SNAPSHOT)), true, flags: JSON_THROW_ON_ERROR);
        $codes = array_column($manifest['blocks'] ?? [], 'code');
        if (($manifest['schema'] ?? null) !== 1 || count($codes) !== 8 || array_diff(self::CODES, $codes) !== [] || array_diff($codes, self::CODES) !== []) {
            throw new RuntimeException('Unexpected approved presentation manifest.');
        }
        $settings = [];
        foreach ($manifest['settings'] as $key => $portableValue) {
            if (! preg_match('/^(?:store_footer_|store_social_|store_announcement_|lavaggio_(?:free_shipping_threshold$|footer_legal_|contact_cards$|faq_groups$)|front_navigation_(?:top_bar|appearance)$)/', $key)) {
                throw new RuntimeException('Unapproved presentation setting: '.$key);
            }
            $value = $this->resolve($portableValue);
            $row = DB::table('system_settings')->where('key', $key)->first();
            $current = $row ? json_decode($row->value, true) : null;
            if ($row && json_last_error() !== JSON_ERROR_NONE) {
                $current = $row->value;
            }
            if (! $row || $current != $value) {
                $settings[$key] = $value;
            }
        }
        $blocks = [];
        foreach ($manifest['blocks'] as $portableBlock) {
            // Resolve every media/filter reference before any setting or block can be written.
            $desired = $this->resolve($portableBlock);
            $current = ContentBlock::query()->where('code', $desired['code'])->with(['translations', 'slots', 'items'])->first();
            $desired['payload'] = $this->mergePayload($current?->payload, $desired['payload']);
            foreach ($desired['translations'] as &$translation) {
                $stored = $current?->translations->firstWhere('locale', $translation['locale']);
                $translation['payload'] = $this->mergePayload($stored?->payload, $translation['payload']);
            }
            unset($translation);
            $state = $current ? Arr::only($current->toArray(), ['code', 'name', 'type', 'is_active', 'payload']) : null;
            if ($state !== null) {
                $locales = array_column($desired['translations'], 'locale');
                $state['translations'] = $current->translations->whereIn('locale', $locales)->sortBy('locale')->map(fn ($row) => Arr::only($row->toArray(), ['locale', 'title', 'subtitle', 'body_html', 'cta_label', 'cta_url', 'payload']))->values()->all();
                $state['slots'] = $current->slots->whereIn('placement', array_column($desired['slots'], 'placement'))->sortBy('sort_order')->map(fn ($row) => Arr::only($row->toArray(), ['placement', 'frontend_variant', 'target_type', 'target_ref', 'sort_order', 'is_active', 'starts_at', 'ends_at']))->values()->all();
                $state['items'] = $current->items->map(fn ($row) => Arr::only($row->toArray(), ['item_type', 'item_id', 'sort_order']))->values()->all();
            }
            if ($state != $desired) {
                $blocks[] = $desired;
            }
        }

        return ['manifest_sha256' => hash_file('sha256', base_path(self::SNAPSHOT)), 'settings' => $settings, 'blocks' => $blocks,
            'counts' => ['settings' => count($settings), 'blocks' => count($blocks),
                'translations' => array_sum(array_map(fn ($block) => count($block['translations']), $blocks)),
                'slots' => array_sum(array_map(fn ($block) => count($block['slots']), $blocks))]];
    }

    public function apply(?array $plan = null): array
    {
        if ($plan !== null && ($plan['manifest_sha256'] ?? '') !== hash_file('sha256', base_path(self::SNAPSHOT))) {
            throw new RuntimeException('The reviewed presentation manifest changed; prepare a new plan.');
        }
        // Re-read the authoritative manifest and target references; caller-supplied rows cannot widen ownership.
        $plan = $this->plan();
        DB::transaction(function () use ($plan): void {
            if ($plan['settings'] !== []) {
                app(SystemSettingsService::class)->putMany($plan['settings']);
            }
            foreach ($plan['blocks'] as $desired) {
                $block = ContentBlock::firstOrNew(['code' => $desired['code']]);
                $block->fill(Arr::only($desired, ['name', 'type', 'is_active', 'payload']));
                if (! $block->exists || $block->isDirty()) {
                    $block->save();
                }
                foreach ($desired['translations'] as $translation) {
                    $row = $block->translations()->firstOrNew(['locale' => $translation['locale']]);
                    $row->fill(Arr::except($translation, ['locale']));
                    if (! $row->exists || $row->isDirty()) {
                        $row->save();
                    }
                }
                $slotIds = [];
                foreach ($desired['slots'] as $slot) {
                    $identity = Arr::only($slot, ['placement', 'frontend_variant', 'target_type', 'target_ref']);
                    $row = $block->slots()->firstOrNew($identity);
                    $row->fill($slot);
                    if (! $row->exists || $row->isDirty()) {
                        $row->save();
                    }
                    $slotIds[] = $row->id;
                }
                $block->slots()->whereIn('placement', array_column($desired['slots'], 'placement'))->whereNotIn('id', $slotIds)->delete();
                // All eight approved local blocks have no manual item links; catalogue records are untouched.
                if ($desired['items'] !== []) {
                    throw new RuntimeException('Unexpected manual items in the approved presentation.');
                }
                $block->items()->delete();
            }
        });

        return $plan['counts'];
    }

    private function mergePayload(mixed $current, array $patch): array
    {
        $result = is_array($current) ? $current : [];
        foreach ($patch as $key => $value) {
            $result[$key] = is_array($value) && ! array_is_list($value)
                ? $this->mergePayload($result[$key] ?? [], $value) : $value;
        }

        return $result;
    }

    private function resolve(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (($value['$ref'] ?? '') === 'attribute_id') {
            $id = DB::table('catalog_attributes')->where('code', $value['code'])->value('id');
            if (! $id) {
                throw new RuntimeException('Missing presentation attribute: '.$value['code']);
            }

            return (int) $id;
        }
        if (($value['$ref'] ?? '') === 'product_image') {
            $product = Product::query()->where('code', $value['code'])->with('media')->first();
            $conversion = (string) ($value['conversion'] ?? '');
            foreach ($product?->media ?? [] as $media) {
                if ($media->collection_name !== 'product_main' || $media->disk !== 'public') {
                    continue;
                }
                $url = $conversion !== '' ? MediaUrl::conversionOrNull($media, $conversion) : null;
                $url ??= MediaUrl::hasUsableOriginal($media) ? $media->getUrl() : null;
                if ($url) {
                    return (string) parse_url($url, PHP_URL_PATH);
                }
            }
            throw new RuntimeException('Missing usable local presentation photograph: '.$value['code']);
        }
        if (($value['$ref'] ?? '') === 'url') {
            $query = http_build_query($this->resolve($value['query']), '', '&', PHP_QUERY_RFC3986);

            return $value['path'].($query !== '' ? '?'.$query : '').(! empty($value['fragment']) ? '#'.$value['fragment'] : '');
        }
        if (isset($value['$ref'])) {
            throw new RuntimeException('Unknown approved presentation reference.');
        }

        return array_map(fn ($item) => $this->resolve($item), $value);
    }
}
