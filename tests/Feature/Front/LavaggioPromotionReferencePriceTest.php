<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioPromotionReferencePriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $this->travelTo(now()->setDate(2026, 10, 28)->startOfDay());
    }

    public function test_current_promotion_does_not_become_its_own_lowest_reference_price(): void
    {
        $product = $this->product();
        $action = $this->promotion($product, 'CURRENT', 20, '2026-10-20', '2026-11-05');

        $price = app(ProductPricePresentationService::class)->forProduct($product);

        $this->assertSame(80.0, $price['current_gross']);
        $this->assertSame(100.0, $price['lowest_30_days_gross']);
        $this->assertSame($action->ends_at->toIso8601String(), $price['promotion_ends_at']);
    }

    public function test_reference_window_is_anchored_to_the_start_of_the_current_promotion(): void
    {
        $product = $this->product();
        $this->promotion($product, 'OLDER', 15, '2026-09-01', '2026-09-15');
        $this->promotion($product, 'CURRENT', 20, '2026-10-01', '2026-11-05');

        $price = app(ProductPricePresentationService::class)->forProduct($product);

        // The September offer is outside the 30 days before today, but inside
        // the 30 days before this October offer started.
        $this->assertSame(85.0, $price['lowest_30_days_gross']);
    }

    public function test_historical_discount_is_only_applied_to_the_base_price_valid_at_that_time(): void
    {
        $product = $this->product();
        ProductPriceHistory::query()->where('product_id', $product->id)->delete();
        ProductPriceHistory::query()->create([
            'product_id' => $product->id, 'price_type' => 'base',
            'old_price' => null, 'new_price' => 100, 'currency_code' => 'EUR',
            'effective_at' => '2026-09-01', 'source' => 'test',
        ]);
        ProductPriceHistory::query()->create([
            'product_id' => $product->id, 'price_type' => 'base',
            'old_price' => 100, 'new_price' => 60, 'currency_code' => 'EUR',
            'effective_at' => '2026-10-10', 'source' => 'test',
        ]);
        $this->promotion($product, 'EARLIER-HALF', 50, '2026-09-25', '2026-10-05');
        $this->promotion($product, 'CURRENT', 20, '2026-10-20', '2026-11-05');

        $price = app(ProductPricePresentationService::class)->forProduct($product);

        $this->assertSame(50.0, $price['lowest_30_days_gross']);
    }

    private function product(): Product
    {
        return Product::query()->create([
            'code' => 'LAV-PROMOTION', 'is_active' => true, 'base_price' => 100, 'stock_qty' => 10,
        ]);
    }

    private function promotion(Product $product, string $code, int $percent, string $start, string $end): CatalogAction
    {
        $action = CatalogAction::query()->create([
            'code' => $code, 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => $percent,
            'target_type' => CatalogAction::TARGET_PRODUCT, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true, 'starts_at' => $start, 'ends_at' => $end,
        ]);
        $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id]);

        return $action;
    }
}
