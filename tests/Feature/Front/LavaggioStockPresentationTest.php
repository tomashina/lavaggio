<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Catalog\StockNotification;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\StockNotificationService;
use App\Services\Front\CartService;
use App\Services\Front\CartStaffAccess;
use App\Services\Front\OrderStockAllocationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('unavailableProducts')]
    public function test_unavailable_product_blocks_cart_and_offers_an_email_subscription(string $mode, int $local, int $supplier): void
    {
        Mail::fake();
        config(['stock_notifications.enabled' => true, 'storefront.brand' => 'lavaggio']);
        $product = $this->product();
        $product->update(['delivery_mode' => $mode, 'stock_qty' => $local, 'supplier_stock_qty' => $supplier]);
        $this->assertFalse($product->storefrontIsPurchasable());
        $response = $this->get(route('products.show', 'paket-za-rublje'))->assertOk()
            ->assertSee('NEDOSTUPNO')->assertSee('Obavijesti me')->assertSee('stock-notifications.css');
        $this->assertMatchesRegularExpression('/data-product-purchase-submit[^>]*\bhidden\b/', $response->getContent());
        $this->assertMatchesRegularExpression('/class="stock-notify-button stock-notify-button-detail"(?:(?!hidden)[^>])+>/', $response->getContent());
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 1])->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertCount(0, app(CartService::class)->lines());
        $this->get(route('stock-notifications.create', ['product_id' => $product->id]))->assertOk()->assertSee('email');
        $this->postJson(route('stock-notifications.store'), ['product_id' => $product->id, 'email' => 'kupac@example.test', 'consent' => true])->assertSuccessful();
        $notification = StockNotification::sole();
        $this->assertFalse(app(StockNotificationService::class)->isAvailable($notification));
        $product->update(['delivery_mode' => 'stock', 'stock_qty' => 3]);
        $this->assertTrue(app(StockNotificationService::class)->isAvailable($notification->fresh()));
        Mail::assertNothingSent();
    }

    public static function unavailableProducts(): array
    {
        return [
            'local delivery cannot use supplier stock' => ['stock', 0, 9],
            'manual unavailable overrides quantities' => ['unavailable', 5, 9],
            'supplier delivery without any stock' => ['supplier', 0, 0],
        ];
    }

    public function test_supplier_stock_requires_the_corresponding_admin_delivery_mode(): void
    {
        $product = $this->product();
        foreach (['supplier', 'order'] as $mode) {
            $product->update(['delivery_mode' => $mode, 'stock_qty' => 0, 'supplier_stock_qty' => 9]);
            $this->assertTrue(app(CartService::class)->add($product, 1));
            $this->assertSame(9, $product->availableStockQuantity());
        }
        $product->update(['delivery_mode' => 'stock']);
        CartService::forgetRequestSnapshot();
        $this->assertCount(0, app(CartService::class)->lines());
        $this->assertFalse(app(CartService::class)->set($product, 1));
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(OrderStockAllocationService::class)->reserve($product, 1));
    }

    public function test_manual_unavailable_also_blocks_a_stocked_variant_and_stock_reservation(): void
    {
        $product = $this->product();
        $product->update(['delivery_mode' => 'unavailable']);
        $option = Option::create(['code' => 'size', 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $value = OptionValue::create(['option_id' => $option->id, 'code' => 'large', 'is_active' => true]);
        $variant = ProductOptionValue::create(['product_id' => $product->id, 'option_value_id' => $value->id,
            'combination_hash' => 'single:'.$value->id, 'sku' => 'VARIANT-L', 'stock_qty' => 20, 'is_active' => true]);
        $this->assertFalse($product->storefrontIsPurchasable());
        $this->assertFalse(app(CartService::class)->add($product, 1, $variant->id));
        $this->get(route('products.show', 'paket-za-rublje'))->assertOk()->assertSee('data-option-stock="0"', false);
        $notification = new StockNotification(['product_id' => $product->id, 'product_option_value_id' => $variant->id, 'locale' => 'hr']);
        $this->assertFalse(app(StockNotificationService::class)->isAvailable($notification));
        $this->expectException(ValidationException::class);
        DB::transaction(fn () => app(OrderStockAllocationService::class)->reserve($product, 1, $variant));
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
