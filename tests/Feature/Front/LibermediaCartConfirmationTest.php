<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibermediaCartConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_liber_media_purchases_open_the_dialog_with_inherited_b2b_config(): void
    {
        config(['storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Liber Media',
            'store_pricing_b2c_enabled' => true,
        ]);
        $product = Product::query()->create([
            'code' => 'LIBER-CART', 'is_active' => true, 'base_price' => 20, 'stock_qty' => 10,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Kocke za djecu', 'slug' => 'liber-cart']);

        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('confirmation', 'modal')
            ->assertJsonPath('summary.item_qty', 3);

        $this->get(route('cart.index'))->assertOk()->assertSee('Kocke za djecu');
    }

    public function test_other_b2b_storefronts_keep_their_notice_when_public_prices_are_enabled(): void
    {
        config(['storefront.brand' => 'auto', 'commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera',
            'store_pricing_b2c_enabled' => true,
        ]);
        $product = Product::query()->create([
            'code' => 'HERRERA-CART', 'is_active' => true, 'base_price' => 20, 'stock_qty' => 10,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Herrera artikl', 'slug' => 'herrera-cart']);

        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertOk()
            ->assertJsonPath('confirmation', 'notice')
            ->assertJsonPath('summary.item_qty', 2);
    }
}
