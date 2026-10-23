<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Admin\Catalog\Bundle\Manager;
use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\BundlePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class BundleModuleTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $group = CustomerGroup::create(['code' => 'bundle-default', 'name' => 'Kupci', 'is_active' => true, 'is_default' => true]);
        $a = Product::create(['code' => 'a', 'sku' => 'a', 'base_price' => 10, 'stock_qty' => 20, 'is_active' => true]);
        $b = Product::create(['code' => 'b', 'sku' => 'b', 'base_price' => 20, 'stock_qty' => 20, 'is_active' => true]);
        $bundle = Bundle::create(['title' => 'Komplet', 'is_active' => true]);
        $bundle->products()->attach([$a->id => ['quantity' => 2], $b->id => ['quantity' => 1]]);
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 10]);

        return [$group, $a, $b, $bundle];
    }

    private function line(Product $p, int $qty): array
    {
        return ['product' => $p, 'quantity' => $qty, 'unit_price' => (float) $p->base_price, 'unit_tax_amount' => (float) $p->base_price * .25,
            'line_total' => (float) $p->base_price * $qty, 'line_tax_total' => (float) $p->base_price * $qty * .25,
            'line_discount_total' => 0, 'display_includes_tax' => true, 'product_option_value_id' => null];
    }

    public function test_complete_sets_discount_only_required_units_and_never_reuse_them(): void
    {
        [, $a, $b, $bundle] = $this->fixture();
        $other = Bundle::create(['title' => 'Drugi', 'is_active' => true, 'sort_order' => 10]);
        $other->products()->sync($bundle->products->mapWithKeys(fn ($p) => [$p->id => ['quantity' => $p->pivot->quantity]])->all());
        $other->discounts()->create($bundle->discounts->first()->only('customer_group_id', 'type', 'value'));
        $lines = app(BundlePricingService::class)->apply(collect([$this->line($a, 3), $this->line($b, 1)]));
        $this->assertEquals(2, $lines[0]['line_discount_total']);
        $this->assertEquals(2, $lines[1]['line_discount_total']);
        $this->assertEquals(5, $lines->sum('line_discount_total') * 1.25);
        $this->assertCount(1, $lines[0]['bundle_ids']);
        $incomplete = app(BundlePricingService::class)->apply(collect([$this->line($a, 1), $this->line($b, 1)]));
        $this->assertEquals(0, $incomplete->sum('line_discount_total'));
    }

    public function test_expired_disabled_and_wrong_audience_bundles_do_not_discount(): void
    {
        [$group, $a, $b, $bundle] = $this->fixture();
        $bundle->update(['ends_at' => now()->subDay()]);
        $lines = collect([$this->line($a, 2), $this->line($b, 1)]);
        $this->assertEquals(0, app(BundlePricingService::class)->apply(clone $lines)->sum('line_discount_total'));
        $bundle->update(['ends_at' => null, 'is_active' => false]);
        $this->assertEquals(0, app(BundlePricingService::class)->apply(clone $lines)->sum('line_discount_total'));
        $bundle->update(['is_active' => true]);
        $group->update(['is_default' => false]);
        $this->assertEquals(0, app(BundlePricingService::class)->apply(clone $lines)->sum('line_discount_total'));
    }

    public function test_fixed_discount_is_gross_and_capped_at_set_value(): void
    {
        [, $a, $b, $bundle] = $this->fixture();
        $bundle->discounts()->update(['type' => 'fixed', 'value' => 5]);
        $lines = app(BundlePricingService::class)->apply(collect([$this->line($a, 2), $this->line($b, 1)]));
        $this->assertEquals(45, $lines->sum('line_total') + $lines->sum('line_tax_total'));
        $bundle->discounts()->update(['value' => 999]);
        $lines = app(BundlePricingService::class)->apply(collect([$this->line($a, 2), $this->line($b, 1)]));
        $this->assertEquals(0, $lines->sum('line_total'));
    }

    public function test_admin_can_create_and_edit_and_validation_rejects_duplicate_products(): void
    {
        [$group, $a, $b] = $this->fixture();
        $user = User::factory()->create();
        Bouncer::assign('superadmin')->to($user);
        $this->actingAs($user);
        Livewire::test(Manager::class)->call('create')->set('title', 'Novi komplet')->set('isActive', true)
            ->set('products', [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 2]])
            ->set('discounts', [['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 15]])->call('save')->assertHasNoErrors();
        $bundle = Bundle::where('title', 'Novi komplet')->sole();
        $this->assertEquals(2, $bundle->products()->count());
        Livewire::test(Manager::class)->call('edit', $bundle->id)->set('title', 'Promijenjen komplet')->call('save')->assertHasNoErrors();
        $this->assertSame('Promijenjen komplet', $bundle->fresh()->title);
        Livewire::test(Manager::class)->call('edit', $bundle->id)
            ->set('products', [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $a->id, 'quantity' => 2]])->call('save')->assertHasErrors();
    }

    public function test_view_only_user_cannot_mutate_bundles(): void
    {
        $user = User::factory()->create();
        Bouncer::allow($user)->to('catalog.actions.view');
        $this->actingAs($user);
        Livewire::test(Manager::class)->call('create')->assertForbidden();
    }
}
