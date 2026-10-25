<?php

namespace Tests\Feature\Catalog;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Action\CatalogActionTarget;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Catalog\ActionResolverService;
use App\Services\Catalog\B2CPromotionService;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\ProductGroupPriceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    public function test_direct_lookups_merge_all_target_types_once_and_retain_exclusivity_and_priority_order(): void
    {
        config(['commerce.b2c_only' => true]);
        $category = Category::create(['code' => 'promotion-category', 'scope' => 'catalog', 'is_active' => true]);
        $secondCategory = Category::create(['code' => 'promotion-second-category', 'scope' => 'catalog', 'is_active' => true]);
        $brand = Manufacturer::create(['code' => 'promotion-brand', 'is_active' => true]);
        $product = Product::create(['code' => 'all-targets', 'manufacturer_id' => $brand->id, 'base_price' => 100, 'is_active' => true]);
        $product->categories()->attach([$category->id, $secondCategory->id]);
        $product->load('categories');
        $base = ['scope' => 'product', 'type' => 'percentage', 'discount_value' => 10, 'audience_type' => 'all', 'is_active' => true];
        $global = CatalogAction::create($base + ['code' => 'all-exclusive', 'target_type' => 'all', 'is_exclusive' => true]);
        $productAction = CatalogAction::create($base + ['code' => 'product-highest-priority', 'target_type' => 'product', 'priority' => 20]);
        $productAction->targets()->create(['target_type' => 'product', 'target_id' => $product->id]);
        $categoryAction = CatalogAction::create($base + ['code' => 'category-two-matching-targets', 'target_type' => 'category', 'priority' => 10]);
        foreach ([$category, $secondCategory] as $target) {
            $categoryAction->targets()->create(['target_type' => 'category', 'target_id' => $target->id]);
        }
        $brandAction = CatalogAction::create($base + ['code' => 'brand-action', 'target_type' => 'manufacturer', 'priority' => 5]);
        $brandAction->targets()->create(['target_type' => 'manufacturer', 'target_id' => $brand->id]);
        $unrelated = CatalogAction::create($base + ['code' => 'unrelated-high-priority', 'target_type' => 'product', 'priority' => 999]);
        $unrelated->targets()->create(['target_type' => 'product', 'target_id' => $product->id + 100]);

        $service = app(B2CPromotionService::class);
        $expected = [$global->id, $productAction->id, $categoryAction->id, $brandAction->id];
        $this->assertSame($expected, $service->forProduct($product)->pluck('id')->values()->all());
        $this->assertSame($expected, $service->forProduct($product)->pluck('id')->values()->all());
    }

    public function test_lookup_contexts_keep_customer_coupon_and_history_separate_and_next_requests_see_changed_actions(): void
    {
        config(['commerce.b2c_only' => true]);
        $product = Product::create(['code' => 'context-product', 'base_price' => 100, 'is_active' => true]);
        $user = User::factory()->create();
        $base = ['scope' => 'product', 'type' => 'percentage', 'discount_value' => 10, 'target_type' => 'all', 'is_active' => true];
        $public = CatalogAction::create($base + ['code' => 'context-public', 'audience_type' => 'all']);
        $private = CatalogAction::create($base + ['code' => 'context-user', 'audience_type' => 'user', 'user_id' => $user->id]);
        $coupon = CatalogAction::create($base + ['code' => 'context-coupon', 'audience_type' => 'all', 'coupon_code' => 'CONTEXT10']);
        $expired = CatalogAction::create($base + ['code' => 'context-expired', 'audience_type' => 'all', 'ends_at' => now()->subDay()]);
        $service = app(B2CPromotionService::class);

        $this->assertSame([$public->id], $service->forProduct($product)->pluck('id')->all());
        $this->assertSame([$private->id, $public->id], $service->forProduct($product, $user)->pluck('id')->all());
        $this->assertSame([$coupon->id, $public->id], $service->forProduct($product, coupon: 'context10')->pluck('id')->all());
        $this->assertSame([$expired->id, $public->id], $service->forProduct($product, history: true)->pluck('id')->all());
        $public->update(['is_active' => false]);
        $this->app->instance('request', Request::create('/fresh-promotion-request'));
        $this->assertCount(0, $service->forProduct($product));
    }

    public function test_filters_and_product_lookups_only_hydrate_matching_schedules_without_losing_model_attributes(): void
    {
        $product = Product::create(['code' => 'lazy-promotions', 'base_price' => 100, 'is_active' => true]);
        $base = ['scope' => 'product', 'type' => 'percentage', 'discount_value' => 15, 'target_type' => 'product', 'audience_type' => 'all', 'is_active' => true];
        $matching = CatalogAction::create($base + ['code' => 'matching-schedule', 'priority' => 20, 'starts_at' => now()->subDay(), 'payload' => ['source' => ['schedule' => 123]], 'usage_limit' => 7]);
        $matching->targets()->createMany([
            ['target_type' => 'product', 'target_id' => $product->id + 100, 'sort_order' => 2],
            ['target_type' => 'product', 'target_id' => $product->id, 'sort_order' => 1],
        ]);
        foreach (range(1, 30) as $number) {
            $unrelated = CatalogAction::create($base + ['code' => 'unrelated-schedule-'.$number]);
            $unrelated->targets()->create(['target_type' => 'product', 'target_id' => $product->id + 1000 + $number]);
        }
        $matching->refresh();

        $retrievedActions = [];
        $retrievedTargets = [];
        Event::listen('eloquent.retrieved: '.CatalogAction::class, function ($action) use (&$retrievedActions): void {
            $retrievedActions[] = $action->id;
        });
        Event::listen('eloquent.retrieved: '.CatalogActionTarget::class, function ($target) use (&$retrievedTargets): void {
            $retrievedTargets[] = $target->id;
        });

        DB::enableQueryLog();
        $service = app(B2CPromotionService::class);
        $query = Product::query();
        $service->filter($query);
        $this->assertSame([], $retrievedActions, 'Filtering must not hydrate every schedule in the catalogue.');
        $resolved = $service->forProduct($product)->first();
        $this->assertSame([$matching->id], $retrievedActions);
        $this->assertCount(2, $retrievedTargets);
        $this->assertInstanceOf(CatalogAction::class, $resolved);
        $this->assertSame($matching->getAttributes(), $resolved->getAttributes());
        $this->assertSame(['source' => ['schedule' => 123]], $resolved->payload);
        $this->assertTrue($resolved->starts_at->equalTo($matching->starts_at));
        $this->assertTrue($resolved->relationLoaded('targets'));
        $this->assertSame([$product->id, $product->id + 100], $resolved->targets->pluck('target_id')->all());
        $this->assertSame($resolved, $service->forProduct($product)->first());
        $this->assertCount(2, DB::getQueryLog(), 'The raw index and hydrated matches must share the same two reads.');

        $this->assertCount(31, $service->actions());
        $this->assertCount(31, $retrievedActions, 'The public full-collection API must still return all normal models.');
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
