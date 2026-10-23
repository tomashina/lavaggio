<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibermediaBundleStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'commerce.b2b_only' => false]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Liber Media',
            'store_pricing_b2c_enabled' => true,
            'store_pricing_prices_include_tax' => false,
        ]);
    }

    public function test_public_bundle_card_shows_the_gross_set_price_and_saving_for_required_quantities(): void
    {
        [$bundle] = $this->bundle();

        $response = $this->get(route('bundles.index'))->assertOk();
        [$xpath, $card] = $this->card($response->getContent());
        $text = $this->text($card);

        $this->assertStringContainsString($bundle->title, $text);
        $this->assertStringContainsString('Cijena kompleta', $text);
        $this->assertStringContainsString('50,00 €', $text);
        $this->assertStringContainsString('45,00 €', $text);
        $this->assertStringContainsString('Ušteda', $text);
        $this->assertStringContainsString('5,00 €', $text);
        $this->assertGreaterThanOrEqual(1, $xpath->query('.//a[@href="'.route('products.show', 'bundle-first').'"]', $card)->count());
        $this->assertGreaterThanOrEqual(1, $xpath->query('.//a[@href="'.route('products.show', 'bundle-second').'"]', $card)->count());
        $buttons = $xpath->query('.//form[@action="'.route('bundles.store', $bundle).'"]//button[@type="submit"]', $card);
        $this->assertSame(1, $buttons->count());
        $button = $buttons->item(0);
        $this->assertFalse($button->hasAttribute('disabled'));
        $this->assertStringContainsString('Dodaj komplet u košaricu', $button->textContent);
        $this->assertSame(1, $xpath->query('.//*[contains(@class, "bag-shopping")]', $button)->count());
        $quantity = $xpath->query('.//form[@action="'.route('bundles.store', $bundle).'"]//input[@name="quantity"]', $card);
        $this->assertSame(1, $quantity->count());
        $this->assertSame('1', $quantity->item(0)->getAttribute('min'));
        $this->assertSame('99', $quantity->item(0)->getAttribute('max'));
        $this->assertSame('1', $quantity->item(0)->getAttribute('value'));
        $this->assertSame([], app(CartService::class)->raw(), 'Browsing a bundle must leave the cart unchanged.');
    }

    public function test_fixed_saving_is_deducted_from_the_complete_tax_inclusive_price(): void
    {
        [$bundle] = $this->bundle();
        $bundle->discounts()->update(['type' => 'fixed', 'value' => 7]);

        $response = $this->get(route('bundles.index'))->assertOk();
        [, $card] = $this->card($response->getContent());
        $text = $this->text($card);
        $this->assertStringContainsString('50,00 €', $text);
        $this->assertStringContainsString('43,00 €', $text);
        $this->assertStringContainsString('7,00 €', $text);

        $this->post(route('bundles.store', $bundle))->assertSessionHasNoErrors()->assertRedirect(route('cart.index'));
        $this->assertEquals(43, app(CartService::class)->lines()->sum('display_line_total'));
    }

    public function test_adding_an_available_bundle_adds_every_required_unit_with_the_advertised_total(): void
    {
        [$bundle, $first, $second] = $this->bundle();

        $this->post(route('bundles.store', $bundle))->assertSessionHasNoErrors()->assertRedirect(route('cart.index'));

        $cart = app(CartService::class);
        $this->assertSame([$first->id => 2, $second->id => 1], collect($cart->raw())->pluck('quantity', 'product_id')->all());
        $this->assertEquals(45, $cart->lines()->sum('display_line_total'));
        $this->assertSame(10, $first->fresh()->stock_qty);
        $this->assertSame(10, $second->fresh()->stock_qty);
    }

    public function test_selected_bundle_quantity_multiplies_every_required_unit_and_the_saving(): void
    {
        [$bundle, $first, $second] = $this->bundle();

        $this->post(route('bundles.store', $bundle), ['quantity' => 2])
            ->assertSessionHasNoErrors()->assertRedirect(route('cart.index'));

        $cart = app(CartService::class);
        $this->assertSame([$first->id => 4, $second->id => 2], collect($cart->raw())->pluck('quantity', 'product_id')->all());
        $lines = $cart->lines();
        $this->assertEquals(90, $lines->sum('display_line_total'));
        $this->assertEquals(10, $lines->sum(fn ($line) => $line['display_base_unit_price'] * $line['quantity'] - $line['display_line_total']));
    }

    public function test_invalid_bundle_quantities_leave_the_cart_unchanged(): void
    {
        [$bundle, $first] = $this->bundle();
        $cart = app(CartService::class);
        $cart->add($first, 1);
        $before = $cart->raw();

        foreach ([0, -1, 100, 1.5, 'dva', null, []] as $quantity) {
            $this->postJson(route('bundles.store', $bundle), ['quantity' => $quantity])
                ->assertUnprocessable()->assertJsonValidationErrors('quantity');
            $this->assertSame($before, $cart->raw());
        }
    }

    public function test_stock_validation_includes_existing_cart_units_for_every_requested_bundle(): void
    {
        [$bundle, $first, $second] = $this->bundle();
        $second->update(['stock_qty' => 2]);
        $cart = app(CartService::class);
        $cart->add($second, 1);
        $before = $cart->raw();

        $this->postJson(route('bundles.store', $bundle), ['quantity' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('bundle');

        $this->assertSame($before, $cart->raw());
        $this->assertArrayNotHasKey($first->id.':0', $cart->raw());
    }

    public function test_bundle_quantity_respects_minimum_and_step_without_adding_extra_units(): void
    {
        [$bundle, $first, $second] = $this->bundle();
        $first->update(['minimum_order_quantity' => 3, 'order_quantity_step' => 2]);
        $cart = app(CartService::class);
        $cart->add($first, 3);

        $this->post(route('bundles.store', $bundle), ['quantity' => 2])
            ->assertSessionHasNoErrors()->assertRedirect(route('cart.index'));
        $this->assertSame([$first->id => 7, $second->id => 2], collect($cart->raw())->pluck('quantity', 'product_id')->all());

        $cart->clear();
        $this->postJson(route('bundles.store', $bundle), ['quantity' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('bundle');
        $this->assertSame([], $cart->raw(), 'The cart must not round four required units up to five.');
    }

    public function test_bundle_purchase_rejects_the_cart_line_limit_without_adding_a_partial_set(): void
    {
        [$bundle, $first, $second] = $this->bundle();
        $first->update(['stock_qty' => 2000]);
        $second->update(['stock_qty' => 2000]);
        $cart = app(CartService::class);
        $cart->add($first, 995);
        $cart->add($second, 998);
        $before = $cart->raw();

        $this->postJson(route('bundles.store', $bundle), ['quantity' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('bundle');

        $this->assertSame($before, $cart->raw());
    }

    public function test_bundle_purchase_restores_the_cart_if_the_cart_service_adjusts_any_quantity(): void
    {
        [$bundle, $first, $second] = $this->bundle();
        $before = [$first->id.':0' => ['product_id' => $first->id, 'product_option_value_id' => null, 'quantity' => 1]];
        $partial = $before + [$second->id.':0' => ['product_id' => $second->id, 'product_option_value_id' => null, 'quantity' => 2]];
        $this->withSession(['front.cart.items' => $before]);
        $this->mock(CartService::class, function ($mock) use ($before, $partial, $first, $second): void {
            $mock->shouldReceive('raw')->twice()->andReturn($before, $partial);
            $mock->shouldReceive('add')->once()->withArgs(fn ($product, $quantity) => $product->id === $first->id && $quantity === 4)->andReturn(true);
            $mock->shouldReceive('add')->once()->withArgs(fn ($product, $quantity) => $product->id === $second->id && $quantity === 2)
                ->andReturnUsing(function () use ($partial): bool {
                    session()->put('front.cart.items', $partial);

                    return true;
                });
        });

        $this->postJson(route('bundles.store', $bundle), ['quantity' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('bundle');

        $this->assertSame($before, session('front.cart.items'));
    }

    public function test_unavailable_bundle_offers_a_stock_notification_and_a_direct_purchase_preserves_the_entire_cart(): void
    {
        [$bundle, $first, $second] = $this->bundle();
        $second->update(['stock_qty' => 0]);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($first, 1));
        $before = $cart->raw();

        $response = $this->get(route('bundles.index'))->assertOk();
        [$xpath, $card] = $this->card($response->getContent());
        $this->assertStringContainsString('Komplet trenutno nije dostupan', $this->text($card));
        $this->assertSame(0, $xpath->query('.//button[not(@disabled)]', $card)->count());
        $notification = $xpath->query('.//a[@data-stock-notify-trigger][@data-bundle-id="'.$bundle->id.'"]', $card);
        $this->assertSame(1, $notification->count());
        $this->assertStringContainsString('Obavijesti me o dostupnosti', $notification->item(0)->textContent);
        $this->assertSame(route('stock-notifications.create', ['bundle_id' => $bundle->id]), $notification->item(0)->getAttribute('href'));

        $this->postJson(route('bundles.store', $bundle))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bundle');

        $this->assertSame($before, $cart->raw(), 'A rejected bundle must not add or increase any product line.');
    }

    private function bundle(): array
    {
        $group = CustomerGroup::query()->create([
            'code' => 'bundle-public', 'name' => 'Kupci', 'is_active' => true, 'is_default' => true,
        ]);
        $tax = TaxRate::query()->create([
            'code' => 'bundle-vat25', 'name' => 'PDV', 'rate_type' => 'percent', 'rate' => 25,
            'is_active' => true, 'is_default' => true,
        ]);
        $products = [];
        foreach (['first' => 10, 'second' => 20] as $code => $price) {
            $product = Product::query()->create([
                'code' => 'BUNDLE-'.$code, 'sku' => 'BUNDLE-'.$code, 'base_price' => $price,
                'stock_qty' => 10, 'supplier_stock_qty' => 0, 'is_active' => true, 'tax_rate_id' => $tax->id,
            ]);
            $product->translations()->create(['locale' => 'hr', 'name' => 'Igračka '.$code, 'slug' => 'bundle-'.$code]);
            $products[] = $product;
        }
        [$first, $second] = $products;
        $bundle = Bundle::query()->create([
            'title' => 'Komplet za igru i učenje', 'is_active' => true,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek(),
        ]);
        $bundle->products()->attach([$first->id => ['quantity' => 2], $second->id => ['quantity' => 1]]);
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 10]);

        return [$bundle, $first, $second];
    }

    private function card(string $html): array
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $cards = $xpath->query('//*[@data-bundle-card]');
        $this->assertSame(1, $cards->count());

        return [$xpath, $cards->item(0)];
    }

    private function text(\DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
