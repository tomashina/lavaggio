<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibermediaPromotionImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_public_special_survives_cent_precision_and_admin_changes(): void
    {
        $this->app['env'] = 'local';
        config(['app.name' => 'Libermedia', 'commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_pricing_b2c_enabled', true);
        $group = CustomerGroup::query()->create(['code' => 'default', 'name' => 'Default', 'is_active' => true, 'payload' => ['opencart' => ['customer_group_id' => 1]]]);
        $other = CustomerGroup::query()->create(['code' => 'business', 'name' => 'Business', 'is_active' => true]);
        $product = Product::query()->create(['code' => 'test', 'sku' => 'test', 'base_price' => '10.0049', 'is_active' => true, 'stock_qty' => 5]);
        $catalog = PriceCatalog::query()->create(['name' => 'Source', 'source_system' => 'herrera-opencart', 'source_snapshot' => config('database.connections.libermedia_source.database'), 'status' => 'draft', 'currency_code' => 'EUR', 'metadata' => ['import_complete' => true]]);
        $catalog->entries()->create(['source_key' => 'product:42', 'product_id' => $product->id, 'kind' => 'base', 'price' => '10.0049', 'is_active' => true, 'payload' => ['opencart' => ['product_id' => 42]]]);
        $base = ['product_id' => $product->id, 'kind' => 'special', 'customer_group_id' => $group->id, 'minimum_quantity' => 1, 'is_active' => true, 'payload' => ['opencart' => ['product_id' => 42]]];
        $catalog->entries()->create($base + ['source_key' => 'product_special:1', 'price' => '6.0060', 'priority' => 0]);
        $catalog->entries()->create($base + ['source_key' => 'product_special:2', 'price' => '1.0000', 'priority' => 1]);
        $catalog->entries()->create(array_replace($base, ['source_key' => 'product_special:3', 'price' => '0.0100', 'customer_group_id' => $other->id]));
        $catalog->entries()->create($base + ['source_key' => 'product_special:4', 'price' => 0]);
        $catalog->entries()->create($base + ['source_key' => 'product_special:5', 'price' => 20]);

        $this->artisan('libermedia:import-promotions')->assertSuccessful();
        $action = CatalogAction::query()->sole();
        $this->assertSame('3.99', $action->discount_value);
        $this->assertSame('all', $action->audience_type);
        $this->assertSame($product->id, $action->targets()->sole()->target_id);
        $this->assertSame('draft', $catalog->fresh()->status);
        $presentation = app(ProductPricePresentationService::class)->forProduct($product, null);
        $this->assertTrue($presentation['has_promotional_discount']);
        $this->assertSame(6.01, $presentation['display_current']);
        $this->assertSame(10.0, $presentation['display_old']);

        $action->update(['discount_value' => 2, 'is_active' => false]);
        $this->artisan('libermedia:import-promotions')->assertSuccessful();
        $this->assertDatabaseCount('catalog_actions', 1);
        $this->assertSame('2.00', $action->fresh()->discount_value);
        $this->assertFalse($action->fresh()->is_active);
        $this->artisan('libermedia:import-promotions', ['--force' => true])->assertSuccessful();
        $this->assertSame('3.99', $action->fresh()->discount_value);
        $this->assertTrue($action->fresh()->is_active);
    }

    public function test_import_is_restricted_to_the_local_libermedia_preview(): void
    {
        config(['app.name' => 'Another shop']);
        $this->artisan('libermedia:import-promotions')->assertFailed();
        $this->assertDatabaseCount('catalog_actions', 0);
    }
}
