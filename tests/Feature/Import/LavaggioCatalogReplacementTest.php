<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Services\Import\LavaggioCatalogReplacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LavaggioCatalogReplacementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Storage::fake('public');
        config(['media-library.queue_conversions_by_default' => false]);
        $category = Category::query()->create(['code' => 'lavaggio-deterdzenti', 'scope' => 'catalog', 'is_active' => true]);
        TaxRate::query()->create(['code' => 'vat', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_active' => true]);
        $brand = Manufacturer::query()->create(['code' => 'old', 'is_active' => true]);
        $old = Product::query()->create(['code' => 'old', 'sku' => '200001', 'barcode' => '8700216708517', 'manufacturer_id' => $brand->id, 'is_active' => true, 'base_price' => 10]);
        $order = DB::table('orders')->insertGetId(['order_number' => 'OLD-1', 'customer_name' => 'Test', 'customer_email' => 'test@example.test', 'grand_total' => 20]);
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $old->id, 'name' => 'Original product', 'sku' => '200001', 'quantity' => 2, 'unit_price' => 10, 'line_total' => 20]);
        $bundle = DB::table('catalog_bundles')->insertGetId(['title' => 'Old bundle', 'is_active' => true]);
        DB::table('catalog_bundle_products')->insert(['bundle_id' => $bundle, 'product_id' => $old->id]);
        $directory = Storage::disk('public')->path('source');
        mkdir($directory.'/images', 0700, true);
        $image = imagecreatetruecolor(20, 30);
        imagewebp($image, $directory.'/images/200001.webp');
        $item = ['sku' => '200001', 'ean' => '8700216708517', 'brand' => 'LENOR', 'brand_code' => 'lavaggio-lenor',
            'name' => 'New product', 'category_code' => $category->code, 'price' => 5.99, 'stock' => 17, 'supplier_stock' => 0,
            'weight_kg' => 0.25, 'image' => 'images/200001.webp', 'image_sha256' => hash_file('sha256', $directory.'/images/200001.webp')];
        $path = $directory.'/catalog.json';
        file_put_contents($path, json_encode(['products' => [$item]]));

        return [$old, $brand, $path, $item];
    }

    public function test_replaces_every_product_keeps_order_snapshots_and_deactivates_old_brands_and_bundles(): void
    {
        [$old, $brand, $path] = $this->fixture();
        $service = app(LavaggioCatalogReplacement::class);
        $snapshot = $service->read($path);
        $result = $service->replace($snapshot);
        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseMissing('products', ['id' => $old->id]);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('order_items', ['product_id' => null, 'sku' => '200001', 'name' => 'Original product', 'line_total' => 20]);
        $this->assertDatabaseHas('orders', ['order_number' => 'OLD-1', 'grand_total' => 20]);
        $this->assertFalse($brand->fresh()->is_active);
        $this->assertDatabaseHas('catalog_bundles', ['is_active' => false]);
        $product = Product::query()->firstOrFail();
        $this->assertSame(17, $product->stock_qty);
        $this->assertSame('0.250', $product->weight_kg);
        $this->assertSame('5.99', $product->base_price);
        $this->assertFileExists($product->getFirstMedia('product_main')->getPath('card_360x240'));
        $product->update(['stock_qty' => 16]);
        $this->assertTrue($service->replace($snapshot)['unchanged']);
        $this->assertSame(16, $product->fresh()->stock_qty);
    }

    public function test_failure_after_old_product_deletion_rolls_back_products_orders_and_brands(): void
    {
        [$old, $brand, $path] = $this->fixture();
        DB::unprepared("CREATE TRIGGER reject_activation BEFORE UPDATE ON products WHEN NEW.is_active = 1 AND OLD.is_active = 0 BEGIN SELECT RAISE(ABORT, 'simulated cutover failure'); END");
        try {
            $service = app(LavaggioCatalogReplacement::class);
            $service->replace($service->read($path));
            $this->fail('Expected rollback');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('simulated cutover failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['id' => $old->id]);
        $this->assertDatabaseHas('order_items', ['product_id' => $old->id]);
        $this->assertTrue($brand->fresh()->is_active);
        $this->assertDatabaseHas('catalog_bundles', ['is_active' => true]);
    }

    public function test_missing_photo_and_duplicate_skus_are_rejected_before_replacement(): void
    {
        [$old, $brand, $path, $item] = $this->fixture();
        file_put_contents($path, json_encode(['products' => [$item, $item]]));
        try {
            app(LavaggioCatalogReplacement::class)->read($path);
            $this->fail('Expected duplicate rejection');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('duplicate', $exception->getMessage());
        }
        file_put_contents($path, json_encode(['products' => [$item]]));
        unlink(dirname($path).'/'.$item['image']);
        try {
            app(LavaggioCatalogReplacement::class)->read($path);
            $this->fail('Expected missing photo rejection');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('photograph', $exception->getMessage());
        }
        $this->assertDatabaseHas('products', ['id' => $old->id]);
        $this->assertTrue($brand->fresh()->is_active);
    }
}
