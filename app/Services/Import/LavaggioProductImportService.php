<?php

namespace App\Services\Import;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Services\Settings\SystemSettingsService;
use App\Support\LavaggioProductFilters;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class LavaggioProductImportService
{
    public function import(array $items, bool $demoPromotions = false, bool $images = true): array
    {
        LavaggioProductFilters::seed();
        $settings = app(SystemSettingsService::class);
        $settings->putMany(['store_pricing_prices_include_tax' => true, 'lavaggio_free_shipping_threshold' => $settings->get('lavaggio_free_shipping_threshold', 50)]);
        $tax = TaxRate::query()->updateOrCreate(['code' => 'lavaggio-pdv-25'], [
            'name' => 'PDV 25%', 'rate' => 25, 'rate_type' => 'percent', 'is_default' => true, 'is_active' => true,
        ]);
        TaxRate::query()->whereKeyNot($tax->id)->update(['is_default' => false]);
        if (! ShippingMethod::query()->where('is_active', true)->exists()) {
            ShippingMethod::query()->firstOrCreate(['code' => 'lavaggio-local-delivery'], [
                'name' => 'Dostava na adresu', 'carrier' => 'manual', 'service_type' => 'home_delivery',
                'pricing_type' => 'flat', 'price' => 4.90, 'free_over' => $settings->get('lavaggio_free_shipping_threshold', 50),
                'description' => 'Ogledna dostava za lokalni webshop.', 'is_active' => true, 'sort_order' => 0,
                'settings' => ['local_demo' => true],
            ]);
        }
        $attributes = Attribute::query()->where('group_code', 'like', 'lavaggio-%')->pluck('id', 'code');
        $nextSkuNumber = 1 + (int) Product::query()->where('sku', 'like', 'LAV-%')->pluck('sku')
            ->map(fn ($sku): int => preg_match('/^LAV-(\d+)$/', (string) $sku, $matches) ? (int) $matches[1] : 0)
            ->max();
        $result = ['products' => 0, 'images' => 0, 'warnings' => []];
        foreach ($items as $index => $item) {
            $ean = trim((string) ($item['ean'] ?? ''));
            if (! preg_match('/^\d{8,14}$/', $ean) || (float) ($item['price'] ?? 0) <= 0 || empty($item['name'])) {
                throw new RuntimeException('Invalid product name, EAN or price in row '.($index + 1));
            }
            if (preg_match('/denkmit|balea|profissimo|dmBio/i', (string) $item['brand'])) {
                throw new RuntimeException('A dm private label was excluded: '.$item['brand']);
            }
            $category = Category::query()->where('scope', Category::SCOPE_CATALOG)->where('code', 'lavaggio-'.$item['category_slug'])->first();
            if (! $category) {
                throw new RuntimeException('Missing category: '.$item['category_slug']);
            }
            $brand = Manufacturer::query()->firstOrCreate(['code' => 'lavaggio-'.Str::slug($item['brand'])], ['is_active' => true, 'is_featured' => true]);
            $brand->translations()->updateOrCreate(['locale' => 'hr'], ['name' => $item['brand'], 'slug' => Str::slug($item['brand'])]);
            $product = Product::query()->firstOrNew(['code' => 'lavaggio-'.$ean]);
            $new = ! $product->exists;
            if (! $product->sku) {
                do {
                    $sku = 'LAV-'.str_pad((string) $nextSkuNumber++, 4, '0', STR_PAD_LEFT);
                } while (Product::query()->where('sku', $sku)->exists());
                $product->sku = $sku;
            }
            $demoMode = match ($index % 20) {
                16 => 'supplier', 17 => 'order', 18 => 'unavailable', default => 'stock'
            };
            $payload = $product->payload ?? [];
            $payload['lavaggio_import'] = [
                'source_url' => $item['source_url'], 'image_url' => $item['image_url'] ?? null,
                'source_price_gross' => (float) $item['price'], 'retrieved_at' => now()->toIso8601String(),
                'local_demo' => true, 'demo_inventory' => true,
                'source_retrieved_at' => $item['source_retrieved_at'] ?? today()->toDateString(),
                'manufacturer_source_url' => $item['manufacturer_source_url'] ?? null,
                'supplemental_source_urls' => $item['supplemental_source_urls'] ?? [],
                'unpublished_fields' => $item['unpublished_fields'] ?? [],
                'origin_note' => $item['origin_note'] ?? null,
            ];
            foreach (['source_legal_original', 'source_legal_provenance', 'source_legal_rejected', 'hazard_notes_review', 'ingredients_source_url', 'source_correction_note', 'source_packaging_image_url', 'source_packaging_image_urls', 'legal_source_note', 'source_notes', 'source_market', 'source_price_currency', 'origin_source_url', 'translation_note'] as $sourceField) {
                if (array_key_exists($sourceField, $item)) {
                    $payload['lavaggio_import'][$sourceField] = $item[$sourceField];
                }
            }
            $product->fill([
                'sku' => $product->sku,
                'barcode' => $ean, 'is_active' => true, 'manufacturer_id' => $brand->id, 'tax_rate_id' => $tax->id,
                'base_price' => $item['price'], 'unit_of_measure' => 'pcs',
                'is_professional' => (bool) ($item['is_professional'] ?? false),
                'content_quantity' => $item['content_quantity'] ?? null, 'content_unit' => $item['content_unit'] ?? null,
                'ingredients' => $item['ingredients'] ?? null, 'hazard_notes' => $item['hazard_notes'] ?? null,
                'manufacturer_importer' => $item['manufacturer_importer'] ?? null, 'country_of_origin' => $item['country_of_origin'] ?? null,
                'payload' => $payload,
            ]);
            if ($new) {
                $product->fill([
                    'listing_price' => $item['price'], 'listed_at' => today(), 'delivery_mode' => $demoMode,
                    'stock_qty' => $demoMode === 'stock' ? ($index % 6 === 1 ? 2 : 8 + $index) : 0,
                    'supplier_stock_qty' => in_array($demoMode, ['supplier', 'order'], true) ? 10 : 0,
                ]);
            }
            $product->save();
            $product->translations()->updateOrCreate(['locale' => 'hr'], [
                'name' => $item['name'], 'slug' => Str::slug($item['name']),
                'description' => $item['description'], 'excerpt' => Str::limit(strip_tags($item['description']), 220),
                'meta_title' => $item['name'].' | Lavaggio', 'meta_description' => Str::limit(strip_tags($item['description']), 155),
            ]);
            $categoryIds = [$category->id => ['is_primary' => true, 'sort_order' => 0]];
            if ($category->parent_id) {
                $categoryIds[$category->parent_id] = ['is_primary' => false, 'sort_order' => 1];
            }
            $product->categories()->sync($categoryIds);
            $ids = [];
            foreach (($item['attributes'] ?? []) as $key => $values) {
                foreach ((array) $values as $value) {
                    $code = LavaggioProductFilters::attributeCode($key, $value);
                    if (isset($attributes[$code])) {
                        $ids[] = $attributes[$code];
                    } else {
                        $result['warnings'][] = 'Unknown filter value: '.$key.' / '.$value.' ('.$ean.')';
                    }
                }
            }
            if ($product->is_professional) {
                $ids[] = $attributes[LavaggioProductFilters::attributeCode('pro', 'PRO')];
            }
            $product->attributes()->sync(array_unique($ids));
            if ($images) {
                foreach (array_merge([$item['image_url'] ?? ''], array_slice($item['gallery_urls'] ?? [], 0, 2)) as $imageIndex => $url) {
                    if ($url === '') {
                        continue;
                    }
                    $collection = $imageIndex === 0 ? 'product_main' : 'product_gallery';
                    if ($product->getMedia($collection)->contains(fn ($media) => $media->getCustomProperty('source_url') === $url)) {
                        continue;
                    }
                    try {
                        $body = Http::timeout(35)->retry(2, 200)->get($url)->throw()->body();
                        $size = @getimagesizefromstring($body);
                        if (! $size || max($size[0], $size[1]) < 900 || strlen($body) > 8 * 1024 * 1024) {
                            throw new RuntimeException('Image does not meet source quality limits.');
                        }
                        if (! in_array($size['mime'], ['image/png', 'image/webp', 'image/jpeg'], true)) {
                            throw new RuntimeException('Unsupported image type.');
                        }
                        $body = app(LavaggioProductImageService::class)->webpOnWhite($body);
                        $product->addMediaFromString($body)->usingName($item['name'])->usingFileName($ean.'-'.$imageIndex.'.webp')
                            ->withCustomProperties([
                                'source_url' => $url, 'width' => $size[0], 'height' => $size[1],
                                'source_mime' => $size['mime'], 'background' => 'white',
                                'lavaggio_image_version' => LavaggioProductImageService::VERSION,
                            ])->toMediaCollection($collection);
                        $result['images']++;
                    } catch (\Throwable $exception) {
                        $result['warnings'][] = 'Image '.$ean.': '.$exception->getMessage();
                    }
                }
            }
            if ($demoPromotions && $index % 5 === 0) {
                $action = CatalogAction::query()->firstOrCreate(['code' => 'lavaggio-demo-'.$ean], [
                    'scope' => CatalogAction::SCOPE_PRODUCT, 'type' => CatalogAction::TYPE_PERCENTAGE,
                    'discount_value' => 20, 'target_type' => CatalogAction::TARGET_PRODUCT,
                    'audience_type' => CatalogAction::AUDIENCE_ALL, 'is_active' => true,
                    'starts_at' => now(), 'ends_at' => now('Europe/Zagreb')->addDays(7)->endOfDay()->timezone(config('app.timezone', 'UTC')),
                    'payload' => ['local_demo' => true],
                ]);
                $action->targets()->firstOrCreate(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id], ['sort_order' => 0]);
                $action->translations()->updateOrCreate(['locale' => 'hr'], ['title' => 'Lokalna ogledna akcija −20%']);
            }
            $result['products']++;
        }
        Cache::flush();

        return $result;
    }
}
