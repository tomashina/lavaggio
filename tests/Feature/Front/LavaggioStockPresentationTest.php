<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Front\CartStaffAccess;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioStockPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.name' => 'Lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia', 'storefront_cache.enabled' => false,
            'commerce.b2b_only' => false, 'commerce.b2c_only' => true,
        ]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Lavaggio', 'store_pricing_prices_include_tax' => true,
            'store_search_autocomplete_enabled' => true,
        ]);
    }

    public function test_all_card_styles_show_only_stock_status_and_keep_package_counts(): void
    {
        $product = $this->product();
        foreach ([[true, true], [true, false], [false, false]] as [$flat, $lined]) {
            $html = Blade::render('<x-front.desktop.product-card :product="$product" :flat="$flat" :lined="$lined" />', compact('product', 'flat', 'lined'));
            $this->assertStringContainsString('NA STANJU', $html);
            $this->assertStringContainsString('34 kom', $html);
            $this->assertStringNotContainsString('lavaggio-stock-quantity', $html);
            $this->assertStringNotContainsString('18473', $html);
            $this->assertStringNotContainsString('92831', $html);
            $this->assertStringNotContainsString('data-product-local-stock', $html);
            $this->assertStringNotContainsString('data-product-supplier-stock', $html);
        }
    }

    public function test_detail_catalog_and_search_results_keep_availability_without_stock_counts(): void
    {
        $this->product();
        foreach ([route('products.show', 'paket-za-rublje'), route('shop.index'), route('shop.index', ['q' => 'Paket'])] as $url) {
            $response = $this->get($url)->assertOk()
                ->assertSee('Paket za rublje')->assertSee('NA STANJU')->assertSee('34 kom')
                ->assertDontSee('lavaggio-stock-quantity', false)
                ->assertDontSee('18.473 kom.')->assertDontSee('18473 kom.')
                ->assertDontSee('92.831 kom.')->assertDontSee('92831 kom.')
                ->assertDontSee('data-product-local-stock', false)->assertDontSee('data-product-supplier-stock', false);
            if ($url === route('products.show', 'paket-za-rublje')) {
                $response->assertSee('name="quantity"', false);
            }
        }
        $this->getJson(route('search.autocomplete', ['q' => 'Paket']))->assertOk()
            ->assertJsonPath('items.0.name', 'Paket za rublje')
            ->assertDontSee('18473', false)->assertDontSee('92831', false);
    }

    public function test_cart_adjustment_does_not_report_inventory_and_keeps_order_quantity(): void
    {
        $product = $this->product();
        $product->update(['stock_qty' => 7, 'supplier_stock_qty' => 0]);
        $this->post(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertRedirect();
        $this->patch(route('cart.items.update', $product), ['quantity' => 8])
            ->assertRedirect()->assertSessionHas('warning', __('ui.cart.status.adjusted_to_availability'));
        $this->assertSame(7, app(CartService::class)->lines()->sole()['quantity']);
        $this->get(route('cart.index'))->assertOk()
            ->assertSee(__('ui.cart.status.adjusted_to_availability'))
            ->assertDontSee('Dostupno je najviše 7 kom.')
            ->assertDontSee('data-cart-staff-stock', false);
    }

    public function test_even_staff_cannot_display_inventory_counts_on_the_lavaggio_storefront(): void
    {
        $staff = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        Bouncer::assign('superadmin')->to($staff);
        $request = Request::create('/cart');
        $request->setUserResolver(fn () => $staff);

        $this->assertFalse(app(CartStaffAccess::class)->canViewStock($request));
        $this->assertSame([], app(CartStaffAccess::class)->stockForLines($request, collect()));
    }

    public function test_bundles_keep_composition_and_order_controls_without_inventory_counts(): void
    {
        $product = $this->product();
        $secondProduct = $product->replicate()->fill(['code' => 'LAV-STOCK-SECOND', 'sku' => 'LAV-STOCK-SECOND']);
        $secondProduct->save();
        $secondProduct->translations()->create(['locale' => 'hr', 'name' => 'Njega rublja', 'slug' => 'njega-rublja']);
        $group = CustomerGroup::create(['code' => 'lavaggio-public', 'name' => 'Kupci', 'is_active' => true, 'is_default' => true]);
        $bundle = Bundle::create(['title' => 'Komplet za rublje', 'is_active' => true]);
        $bundle->products()->attach([$product->id => ['quantity' => 2], $secondProduct->id => ['quantity' => 1]]);
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 10]);

        $this->get(route('bundles.index'))->assertOk()
            ->assertSee('Komplet je dostupan')->assertSee('2 kom.')
            ->assertSee('name="quantity"', false)
            ->assertDontSee('18473', false)->assertDontSee('92831', false);
    }

    private function product(): Product
    {
        $product = Product::create([
            'code' => 'LAV-STOCK', 'sku' => 'LAV-STOCK', 'is_active' => true, 'base_price' => 20,
            'stock_qty' => 18473, 'supplier_stock_qty' => 92831, 'delivery_mode' => 'stock',
            'content_quantity' => 34, 'content_unit' => 'pcs',
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Paket za rublje', 'slug' => 'paket-za-rublje']);

        return $product;
    }
}
