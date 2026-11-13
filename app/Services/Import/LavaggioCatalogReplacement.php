<?php

namespace App\Services\Import;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class LavaggioCatalogReplacement
{
    public function read(string $path): array
    {
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $items = $data['products'] ?? [];
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new RuntimeException('An empty or invalid catalogue cannot replace existing products.');
        }
        $skus = $eans = [];
        foreach ($items as &$item) {
            $sku = (string) ($item['sku'] ?? '');
            $ean = (string) ($item['ean'] ?? '');
            if (! preg_match('/^\d{6}$/', $sku) || ! preg_match('/^\d{13}$/', $ean)
                || isset($skus[$sku]) || isset($eans[$ean]) || trim($item['name'] ?? '') === ''
                || trim($item['brand'] ?? '') === '' || ! str_starts_with($item['brand_code'] ?? '', 'lavaggio-')
                || ! is_numeric($item['price'] ?? null) || $item['price'] <= 0
                || ! is_int($item['stock'] ?? null) || $item['stock'] < 0
                || ! is_int($item['supplier_stock'] ?? null) || $item['supplier_stock'] < 0
                || (($item['weight_kg'] ?? null) !== null && (! is_numeric($item['weight_kg']) || $item['weight_kg'] <= 0))) {
                throw new RuntimeException('Invalid or duplicate product: '.$sku);
            }
            $skus[$sku] = $eans[$ean] = true;
            $category = Category::query()->where('scope', 'catalog')->where('code', $item['category_code'])->first();
            if (! $category || ! $category->is_active) {
                throw new RuntimeException('Missing active category: '.$item['category_code']);
            }
            $item['category_id'] = $category->id;
            $item['category_parent_id'] = $category->parent_id;
            if (($item['image'] ?? '') !== 'images/'.$sku.'.webp') {
                throw new RuntimeException('Unexpected image path: '.$sku);
            }
            $image = dirname($path).'/'.$item['image'];
            $size = is_file($image) ? @getimagesize($image) : false;
            if (! $size || $size['mime'] !== 'image/webp' || ! hash_equals($item['image_sha256'], hash_file('sha256', $image))) {
                throw new RuntimeException('Missing, invalid or changed photograph: '.$sku);
            }
            $item['image_path'] = $image;
        }
        unset($item);

        return ['products' => $items, 'sha256' => hash_file('sha256', $path), 'source_url' => $data['source_url'] ?? ''];
    }

    public function alreadyApplied(array $snapshot): bool
    {
        $products = Product::query()->get(['sku', 'payload']);

        return $products->count() === count($snapshot['products'])
            && $products->pluck('sku')->sort()->values()->all() === collect($snapshot['products'])->pluck('sku')->sort()->values()->all()
            && $products->every(fn ($product) => data_get($product->payload, 'catalog_replacement.sha256') === $snapshot['sha256']);
    }

    public function replace(array $snapshot): array
    {
        if ($this->alreadyApplied($snapshot)) {
            return ['unchanged' => true, 'products' => count($snapshot['products'])];
        }
        config(['media-library.queue_conversions_by_default' => false]);

        $result = DB::transaction(function () use ($snapshot): array {
            $oldIds = Product::query()->lockForUpdate()->pluck('id')->all();
            $orderDigest = $this->orderDigest();
            $tax = TaxRate::query()->where('rate', 25)->where('is_active', true)->firstOrFail();
            $brandIds = [];
            $newIds = [];
            foreach ($snapshot['products'] as $item) {
                // Stage with temporary identifiers so replacement also works for overlapping EANs/SKUs.
                $brand = Manufacturer::query()->firstOrCreate(['code' => $item['brand_code']]);
                $brand->update(['is_active' => true, 'is_featured' => true]);
                $brand->translations()->updateOrCreate(['locale' => 'hr'], [
                    'name' => $item['brand'], 'slug' => Str::slug($item['brand_code'] === 'lavaggio-dr-beckmann' ? 'Dr. Beckmann' : $item['brand']),
                ]);
                $brandIds[] = $brand->id;
                $product = Product::query()->create([
                    'code' => 'replacement-'.Str::uuid(), 'sku' => 'replacement-'.Str::uuid(),
                    'barcode' => null, 'manufacturer_id' => $brand->id, 'tax_rate_id' => $tax->id,
                    'is_active' => false, 'is_professional' => (bool) ($item['is_professional'] ?? false),
                    'unit_of_measure' => 'pcs', 'base_price' => $item['price'], 'listing_price' => $item['price'], 'listed_at' => today(),
                    'stock_qty' => $item['stock'], 'supplier_stock_qty' => $item['supplier_stock'],
                    'delivery_mode' => $item['stock'] > 0 ? 'stock' : ($item['supplier_stock'] > 0 ? 'supplier' : 'unavailable'),
                    'weight_kg' => $item['weight_kg'], 'content_quantity' => $item['content_quantity'] ?? null,
                    'content_unit' => $item['content_unit'] ?? null,
                    'payload' => ['catalog_replacement' => ['sha256' => $snapshot['sha256'], 'source_url' => $snapshot['source_url'],
                        'source_row' => $item['source_row'] ?? null, 'sku' => $item['sku'], 'source_image' => $item['source_image'] ?? null]],
                ]);
                $newIds[$item['sku']] = $product->id;
                $product->translations()->create(['locale' => 'hr', 'name' => $item['name'],
                    'slug' => Str::slug($item['name']).'-'.$item['sku'], 'description' => '<p>'.e($item['name']).'</p>',
                    'excerpt' => $item['name'], 'meta_title' => Str::limit($item['name'], 160).' | Lavaggio']);
                $categories = [$item['category_id'] => ['is_primary' => true, 'sort_order' => 0]];
                if ($item['category_parent_id']) {
                    $categories[$item['category_parent_id']] = ['is_primary' => false, 'sort_order' => 1];
                }
                $product->categories()->sync($categories);
                $media = $product->addMedia($item['image_path'])->preservingOriginal()->usingName($item['name'])
                    ->withCustomProperties(['source_sha256' => $item['image_sha256'], 'source_sku' => $item['sku'], 'background' => 'white'])
                    ->toMediaCollection('product_main');
                foreach (['thumb_100x100', 'card_360x240', 'card_320w', 'card_480w', 'card_720w', 'detail_960x960'] as $conversion) {
                    if (! $media->hasGeneratedConversion($conversion) || ! is_file($media->getPath($conversion))) {
                        throw new RuntimeException('Image conversion missing: '.$item['sku'].' / '.$conversion);
                    }
                }
            }

            $bundleIds = DB::table('catalog_bundle_products')->whereIn('product_id', $oldIds)->pluck('bundle_id');
            DB::table('catalog_bundles')->whereIn('id', $bundleIds)->update(['is_active' => false]);
            DB::table('catalog_bundle_products')->whereIn('product_id', $oldIds)->delete();
            DB::table('catalog_price_entries')->whereIn('product_id', $oldIds)->delete();
            $actionIds = DB::table('catalog_action_targets')->where('target_type', CatalogAction::TARGET_PRODUCT)->whereIn('target_id', $oldIds)->pluck('action_id');
            DB::table('catalog_actions')->whereIn('id', $actionIds)->update(['is_active' => false]);
            DB::table('catalog_actions')->whereIn('gift_product_id', $oldIds)->update(['is_active' => false]);
            DB::table('catalog_action_targets')->where('target_type', CatalogAction::TARGET_PRODUCT)->whereIn('target_id', $oldIds)->delete();
            DB::table('content_block_items')->where('item_type', 'product')->whereIn('item_id', $oldIds)->delete();
            DB::table('content_comments')->where('commentable_type', (new Product)->getMorphClass())->whereIn('commentable_id', $oldIds)->delete();
            // Preserve old physical files for database-backup rollback; remove their database associations.
            DB::table('media')->where('model_type', (new Product)->getMorphClass())->whereIn('model_id', $oldIds)->delete();
            DB::table('products')->whereIn('id', $oldIds)->delete();
            foreach ($snapshot['products'] as $item) {
                Product::query()->whereKey($newIds[$item['sku']])->update([
                    'code' => 'lavaggio-'.$item['sku'], 'sku' => $item['sku'], 'barcode' => $item['ean'], 'is_active' => true,
                ]);
            }
            Manufacturer::query()->whereNotIn('id', $brandIds)->update(['is_active' => false, 'is_featured' => false]);
            if ($orderDigest !== $this->orderDigest()) {
                throw new RuntimeException('Order history changed unexpectedly; rolling back.');
            }
            if (Product::query()->count() !== count($snapshot['products'])) {
                throw new RuntimeException('Product count mismatch; rolling back.');
            }

            return ['removed' => count($oldIds), 'products' => count($newIds), 'images' => count($newIds), 'brands' => count(array_unique($brandIds)), 'orders_preserved' => true];
        });
        Cache::flush();

        return $result;
    }

    private function orderDigest(): string
    {
        $hash = hash_init('sha256');
        foreach (['orders', 'order_items', 'order_totals', 'order_history'] as $table) {
            foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                $values = (array) $row;
                // These nullable foreign keys intentionally disappear when the catalogue is replaced.
                unset($values['product_id'], $values['product_option_value_id']);
                hash_update($hash, $table.json_encode($values, JSON_THROW_ON_ERROR));
            }
        }

        return hash_final($hash);
    }
}
