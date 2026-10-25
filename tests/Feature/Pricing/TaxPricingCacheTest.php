<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Services\Pricing\TaxPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaxPricingCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_reuses_the_default_rate_and_keeps_explicit_product_tax(): void
    {
        $fallback = $this->rate('default', 25, true);
        $explicit = $this->rate('reduced', 13);
        $product = Product::create(['code' => 'tax-default', 'base_price' => 100]);
        $product->load('taxRate');
        $reduced = Product::create(['code' => 'tax-reduced', 'base_price' => 100, 'tax_rate_id' => $explicit->id]);
        $reduced->load('taxRate');
        DB::enableQueryLog();
        try {
            foreach (range(1, 20) as $unused) {
                $this->assertSame(125.0, app(TaxPricingService::class)->grossFromNet(100, $product));
            }
            $this->assertSame(113.0, app(TaxPricingService::class)->grossFromNet(100, $reduced));
            $taxQueries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'tax_rates'));
            $this->assertCount(1, $taxQueries);
        } finally {
            DB::disableQueryLog();
        }
        $fallback->update(['rate' => 20]);
        $this->assertSame(120.0, app(TaxPricingService::class)->grossFromNet(100, $product));
        $fallback->delete();
        $this->assertSame($explicit->id, app(TaxPricingService::class)->resolveRateForProduct($product)?->id);
    }

    public function test_missing_rate_is_reused_but_new_requests_and_saved_rates_are_visible(): void
    {
        DB::enableQueryLog();
        try {
            foreach (range(1, 20) as $unused) {
                $this->assertSame(100.0, app(TaxPricingService::class)->grossFromNet(100));
            }
            $taxQueries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'tax_rates'));
            $this->assertCount(1, $taxQueries);
        } finally {
            DB::disableQueryLog();
        }
        $rate = $this->rate('new-default', 25, true);
        $this->assertSame(125.0, app(TaxPricingService::class)->grossFromNet(100));
        // Imports may write directly; every subsequent request must see that change.
        DB::table('tax_rates')->where('id', $rate->id)->update(['rate' => 10]);
        $this->app->instance('request', Request::create('/fresh-tax-request'));
        $this->assertSame(110.0, app(TaxPricingService::class)->grossFromNet(100));
    }

    private function rate(string $code, float $rate, bool $default = false): TaxRate
    {
        return TaxRate::create([
            'code' => $code, 'name' => $code, 'rate' => $rate, 'rate_type' => 'percent',
            'is_active' => true, 'is_default' => $default, 'sort_order' => 0,
        ]);
    }
}
