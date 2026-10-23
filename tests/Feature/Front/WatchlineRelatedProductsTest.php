<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\OrderStatus;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchlineRelatedProductsTest extends TestCase
{
    use RefreshDatabase;

    private Manufacturer $brand;

    private OrderStatus $complete;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'hr', 'storefront.theme' => 'herrera', 'storefront.home' => 'watchline', 'storefront.brand' => 'watchline', 'commerce.b2b_only' => true]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Watchline',
            'store_product_desktop_default_cols' => 6,
            'catalog_hide_out_of_stock_products' => false,
        ]);
        $this->brand = Manufacturer::create(['code' => 'related-cluse', 'is_active' => true]);
        $this->brand->translations()->create(['locale' => 'hr', 'name' => 'CLUSE', 'slug' => 'related-cluse']);
        $this->complete = OrderStatus::create(['code' => 'herrera-oc-status-5', 'name' => 'Complete', 'is_paid' => false, 'is_cancelled' => false]);
    }

    public function test_related_products_use_only_the_current_brand_and_rank_completed_quantities_before_stable_unsold_fallbacks(): void
    {
        $main = $this->product('main');
        $best = $this->product('best');
        $second = $this->product('second');
        $paid = $this->product('paid');
        $firstUnsold = $this->product('first-unsold', ['stock_qty' => 0]);
        $secondUnsold = $this->product('second-unsold');
        $inactive = $this->product('inactive', ['is_active' => false]);
        $otherBrand = Manufacturer::create(['code' => 'related-other', 'is_active' => true]);
        $foreign = $this->product('foreign', ['manufacturer_id' => $otherBrand->id]);
        $main->update(['payload' => ['related_product_ids' => [$foreign->id, $secondUnsold->id]]]);
        $this->sale($best, 4, $this->complete);
        $this->sale($best, 3, $this->complete);
        foreach (range(1, 3) as $unused) {
            $this->sale($second, 2, $this->complete);
        }
        $this->sale($paid, 2, OrderStatus::create(['code' => 'paid-local', 'name' => 'Paid', 'is_paid' => true]));
        foreach ([null,
            OrderStatus::create(['code' => 'pending', 'name' => 'Pending']),
            OrderStatus::create(['code' => 'cancelled', 'name' => 'Cancelled', 'is_paid' => true, 'is_cancelled' => true]),
            OrderStatus::create(['code' => 'refunded', 'name' => 'Refunded']),
        ] as $status) {
            $this->sale($second, 100, $status);
        }
        foreach ([$main, $inactive, $foreign] as $excluded) {
            $this->sale($excluded, 999, $this->complete);
        }

        $this->withSession(['front_recently_viewed_products' => [$foreign->id]])
            ->get(route('products.show', ['slug' => 'watchline-related-main']))
            ->assertOk()
            ->assertViewHas('related', fn ($products) => $products->pluck('id')->all() === [$best->id, $second->id, $paid->id, $firstUnsold->id, $secondUnsold->id])
            ->assertViewHas('recentlyViewed', fn ($products) => $products->pluck('id')->all() === [$foreign->id]);
    }

    public function test_same_brand_recommendations_respect_existing_local_or_supplier_stock_visibility(): void
    {
        app(SystemSettingsService::class)->put('catalog_hide_out_of_stock_products', true);
        $this->product('main');
        $unavailable = $this->product('unavailable', ['stock_qty' => 0]);
        $supplier = $this->product('supplier', ['stock_qty' => 0, 'supplier_stock_qty' => 2]);
        $local = $this->product('local');
        $this->sale($unavailable, 100, $this->complete);
        $this->sale($supplier, 8, $this->complete);
        $this->sale($local, 7, $this->complete);

        $this->get(route('products.show', ['slug' => 'watchline-related-main']))
            ->assertOk()
            ->assertViewHas('related', fn ($products) => $products->pluck('id')->all() === [$supplier->id, $local->id]);
    }

    public function test_a_product_without_a_brand_does_not_receive_unrelated_brand_fallbacks(): void
    {
        $main = $this->product('main', ['manufacturer_id' => null]);
        $other = $this->product('other');
        $main->update(['payload' => ['related_product_ids' => [$other->id]]]);
        $this->sale($other, 10, $this->complete);

        $this->get(route('products.show', ['slug' => 'watchline-related-main']))
            ->assertOk()
            ->assertViewHas('related', fn ($products) => $products->isEmpty());
    }

    private function product(string $code, array $attributes = []): Product
    {
        $product = Product::create(array_replace(['code' => 'watchline-related-'.$code, 'manufacturer_id' => $this->brand->id, 'is_active' => true, 'stock_qty' => 3, 'base_price' => 99], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => 'watchline-related-'.$code]);

        return $product;
    }

    private function sale(Product $product, int $quantity, ?OrderStatus $status): void
    {
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'RELATED-'.DB::table('orders')->count(), 'status_id' => $status?->id, 'customer_name' => 'Fixture', 'customer_email' => 'fixture@example.test']);
        DB::table('order_items')->insert(['order_id' => $orderId, 'product_id' => $product->id, 'quantity' => $quantity, 'name' => 'Fixture watch']);
    }
}
