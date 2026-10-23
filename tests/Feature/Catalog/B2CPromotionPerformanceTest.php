<?php

namespace Tests\Feature\Catalog;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Catalog\ActionResolverService;
use App\Services\Catalog\B2CPromotionService;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\ProductGroupPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2CPromotionPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_promotions_load_once_for_many_cards_and_filter_without_correlated_queries(): void
    {
        config(['commerce.b2c_only' => true]);
        $products = collect(range(1, 25))->map(fn ($i) => Product::create(['code' => 'p'.$i, 'sku' => 'p'.$i, 'base_price' => 100, 'is_active' => true]));
        $action = CatalogAction::create(['code' => 'public', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 20, 'target_type' => 'product', 'audience_type' => 'all', 'is_active' => true]);
        $action->targets()->createMany($products->map(fn ($p) => ['target_type' => 'product', 'target_id' => $p->id])->all());
        $this->app['request']->attributes->remove('b2c.promotions.0.'.hash('sha256', '').'.0');
        DB::enableQueryLog();
        foreach ($products as $p) {
            $this->assertSame($action->id, app(ActionResolverService::class)->resolveProductAction($p)?->id);
        }
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries, 'Actions and targets must be shared by all product cards.');
        $query = Product::query();
        app(B2CPromotionService::class)->filter($query);
        $this->assertStringNotContainsString('exists', strtolower($query->toSql()));
        $this->assertSame(25, $query->count());
        $action->update(['ends_at' => now()->subDay()]);
        $this->app['request']->attributes->remove('b2c.promotions.0.'.hash('sha256', '').'.0');
        $query = Product::query();
        app(B2CPromotionService::class)->filter($query);
        $this->assertSame(0, $query->count());
    }

    public function test_target_index_preserves_order_and_keeps_product_matches_separate(): void
    {
        config(['commerce.b2c_only' => true]);
        $first = Product::create(['code' => 'first', 'sku' => 'first', 'base_price' => 100, 'is_active' => true]);
        $second = Product::create(['code' => 'second', 'sku' => 'second', 'base_price' => 100, 'is_active' => true]);
        $base = ['scope' => 'product', 'type' => 'percentage', 'discount_value' => 20, 'audience_type' => 'all', 'is_active' => true];
        $global = CatalogAction::create($base + ['code' => 'global', 'target_type' => 'all', 'priority' => 1]);
        $specific = CatalogAction::create($base + ['code' => 'specific', 'target_type' => 'product', 'priority' => 10]);
        $specific->targets()->create(['target_type' => 'product', 'target_id' => $first->id]);
        $service = app(B2CPromotionService::class);
        $this->assertSame([$specific->id, $global->id], $service->forProduct($first)->pluck('id')->values()->all());
        $this->assertSame([$global->id], $service->forProduct($second)->pluck('id')->values()->all());
        $this->assertSame([$specific->id, $global->id], $service->forProduct($first)->pluck('id')->values()->all());
    }

    public function test_b2c_customer_skips_all_business_account_and_contract_price_queries(): void
    {
        config(['commerce.b2c_only' => true, 'commerce.b2b_only' => true]);
        $user = User::factory()->create();
        $p = Product::create(['code' => 'plain', 'sku' => 'plain', 'base_price' => 10, 'is_active' => true]);
        DB::enableQueryLog();
        $access = app(B2BAccessService::class);
        $this->assertFalse($access->requiresApprovedAccount());
        $this->assertTrue($access->canViewPrices($user));
        $this->assertFalse($access->displaysNet($user));
        $this->assertNull($access->approvedAccount($user));
        $this->assertNull(app(ProductGroupPriceResolver::class)->resolve($p, $user));
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_coupon_and_private_business_actions_are_not_public_promotions(): void
    {
        config(['commerce.b2c_only' => true]);
        $p = Product::create(['code' => 'plain', 'sku' => 'plain', 'base_price' => 100, 'is_active' => true]);
        $base = ['scope' => 'product', 'type' => 'percentage', 'discount_value' => 20, 'target_type' => 'all', 'is_active' => true];
        CatalogAction::create($base + ['code' => 'coupon', 'coupon_code' => 'SAVE20', 'audience_type' => 'all']);
        CatalogAction::create($base + ['code' => 'business', 'audience_type' => 'user_group']);
        $this->assertNull(app(ActionResolverService::class)->resolveProductAction($p));
        $this->assertSame('coupon', app(ActionResolverService::class)->resolveProductAction($p, couponCode: 'save20')?->code);
    }
}
