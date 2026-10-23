<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Front\CartService;
use App\Services\GiftVoucherService;
use App\Services\Payments\WSPayFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class GiftVoucherFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        config(['commerce.b2b_only' => false, 'commerce.b2c_only' => true, 'commerce.local_safe_mode' => true, 'gift_vouchers.enabled' => true, 'gift_vouchers.emails_enabled' => false]);
        Mail::fake();
        Http::preventStrayRequests();
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'new', 'name' => 'Čeka uplatu', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::create(['code' => 'standard', 'name' => 'Dostava', 'price' => 0, 'is_active' => true]);
        PaymentMethod::create(['code' => 'bank', 'name' => 'Uplata na račun', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    public function test_purchase_page_has_the_full_amount_range_and_croatian_recipient_billing_and_payment_form(): void
    {
        $response = $this->get(route('gift-vouchers.create'))->assertOk()->assertViewIs('front.desktop.gift-vouchers.create');
        $response->assertSee('Darujte igru. Prepustite im izbor.')
            ->assertSee('name="recipient_email"', false)->assertSee('name="customer_email"', false)
            ->assertSee('name="billing_address_line_1"', false)->assertSee('name="accept_terms"', false)
            ->assertSee('value="10"', false)->assertSee('value="300"', false)
            ->assertSee('data-gift-preview-recipient', false)->assertSee('Kupi poklon bon');
        $this->assertDatabaseCount('orders', 0);
        Mail::assertNothingSent();
    }

    public function test_amount_changes_use_server_quoted_payment_fees_without_exposing_gateway_settings(): void
    {
        PaymentMethod::where('code', 'bank')->update(['fee_type' => 'percent', 'fee_value' => 5, 'settings' => ['secret_key' => 'NEVER-PUBLIC']]);
        $response = $this->getJson(route('gift-vouchers.create', ['amount' => 100]))->assertOk()
            ->assertJsonPath('amount', 100)->assertJsonPath('payment_methods.0.payment_fee_total', 5);
        $this->assertArrayNotHasKey('settings', $response->json('payment_methods.0'));
        $response->assertDontSee('NEVER-PUBLIC');
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_purchase_creates_a_pending_voucher_and_preserves_the_existing_product_cart_and_coupon(): void
    {
        $items = ['123:0' => ['product_id' => 123, 'product_option_value_id' => null, 'quantity' => 2]];
        $response = $this->withSession(['front.cart.items' => $items, 'front.cart.coupon_code' => 'EXISTING'])
            ->post(route('gift-vouchers.store'), $this->payload())->assertRedirect();
        $order = Order::sole();
        $voucher = GiftVoucher::sole();
        $response->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]))
            ->assertSessionHas('front.cart.items', $items)->assertSessionHas('front.cart.coupon_code', 'EXISTING')
            ->assertSessionHas('front.checkout.last_order_id', $order->id)
            ->assertSessionHas('front.checkout.last_order_number', $order->order_number);
        $this->assertTrue((bool) data_get($order->payload, 'gift_voucher_purchase'));
        $this->assertSame('pending', $voucher->status);
        $this->assertSame('ana@example.test', $voucher->recipient_email);
        $this->assertNull($voucher->code);
        $this->assertNull($order->paid_at);
        $this->assertSame(50.0, (float) $order->grand_total);
        Mail::assertNothingSent();
    }

    public function test_invalid_amount_recipient_and_missing_consent_do_not_create_a_purchase(): void
    {
        foreach ([5, 15, 310] as $amount) {
            $this->post(route('gift-vouchers.store'), array_replace($this->payload(), ['amount' => $amount]))
                ->assertSessionHasErrors('amount');
        }
        $this->post(route('gift-vouchers.store'), array_replace($this->payload(), ['recipient_email' => 'nije-email', 'accept_terms' => '0']))
            ->assertSessionHasErrors(['recipient_email', 'accept_terms']);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('gift_vouchers', 0);
    }

    public function test_cash_on_delivery_cannot_be_used_to_purchase_a_digital_voucher(): void
    {
        PaymentMethod::create(['code' => 'cod', 'name' => 'Pouzećem', 'provider' => 'cod', 'is_active' => true]);
        $this->post(route('gift-vouchers.store'), array_replace($this->payload(), ['payment_method_code' => 'cod']))
            ->assertSessionHasErrors('payment_method_code');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_can_apply_and_remove_a_voucher_without_consuming_its_balance(): void
    {
        $voucher = $this->voucher();
        app(CartService::class)->add($this->product(), 2);
        $this->postJson(route('cart.gift-voucher.apply'), ['gift_voucher_code' => ' '.strtolower($voucher->code).' '])
            ->assertOk()->assertJsonPath('code', $voucher->code)
            ->assertJsonPath('amount', 20)->assertJsonPath('remaining_total', 0)
            ->assertSessionHas('front.cart.gift_voucher_code', $voucher->code);
        $this->assertSame(50.0, (float) $voucher->fresh()->balance);
        $this->assertDatabaseCount('gift_voucher_redemptions', 0);
        $this->deleteJson(route('cart.gift-voucher.remove'))->assertOk()->assertJsonPath('code', '')
            ->assertJsonPath('remaining_total', 20)->assertSessionMissing('front.cart.gift_voucher_code');
    }

    public function test_invalid_code_does_not_replace_an_already_applied_voucher(): void
    {
        $voucher = $this->voucher();
        $this->withSession(['front.cart.gift_voucher_code' => $voucher->code])
            ->postJson(route('cart.gift-voucher.apply'), ['gift_voucher_code' => 'POGRESAN-KOD'])
            ->assertUnprocessable()->assertJsonValidationErrors('gift_voucher_code')
            ->assertSessionHas('front.cart.gift_voucher_code', $voucher->code);
    }

    public function test_checkout_displays_and_refreshes_the_server_deduction_and_has_separate_redemption_forms(): void
    {
        $voucher = $this->voucher();
        app(CartService::class)->add($this->product(), 2);
        $this->withSession(['front.cart.gift_voucher_code' => $voucher->code]);
        $response = $this->get(route('checkout.create'))->assertOk()
            ->assertSee('data-summary-gift-voucher', false)->assertSee('data-gift-voucher-apply', false);
        $html = $response->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//form[@data-checkout-form]//form')->length);
        $this->assertSame(1, $xpath->query('//aside//*[@data-gift-voucher-redemption]')->length);
        $this->assertSame(1, $xpath->query('//aside//*[@data-checkout-coupon]')->length);
        $this->assertSame(1, $xpath->query('//form[@id="gift-voucher-checkout-apply" and not(ancestor::form)]')->length);
        $this->assertSame('gift-voucher-checkout-apply', $xpath->query('//*[@name="gift_voucher_code"]')->item(0)->getAttribute('form'));
        $this->getJson(route('checkout.options', ['billing_country_code' => 'HR', 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank']))
            ->assertOk()->assertJsonPath('totals.gift_voucher_total', 20)
            ->assertJsonPath('totals.grand_total', 0)->assertJsonPath('totals.original_total', 20);
    }

    public function test_signed_purchase_receipt_shows_the_target_order_without_disclosing_the_voucher_code(): void
    {
        $order = app(GiftVoucherService::class)->purchase($this->payload());
        $voucher = GiftVoucher::sole();
        $voucher->storeCode('LIBER-PRIVATE-NOT-FOR-RECEIPT');
        $voucher->save();
        $this->get(route('gift-vouchers.purchase', ['orderNumber' => $order->order_number]))->assertForbidden();
        $signed = URL::temporarySignedRoute('gift-vouchers.purchase', now()->addDays(30), ['orderNumber' => $order->order_number]);
        $this->get($signed)->assertOk()->assertSee('Ana')->assertSee('ana@example.test')
            ->assertSee($order->order_number)->assertDontSee('LIBER-PRIVATE-NOT-FOR-RECEIPT');
        $this->get(str_replace($order->order_number, 'OTHER-ORDER', $signed))->assertForbidden();
    }

    public function test_cancelled_gift_payment_cannot_replace_the_existing_product_cart(): void
    {
        $order = app(GiftVoucherService::class)->purchase($this->payload());
        $order->update(['payment_method_code' => 'wspay']);
        $items = ['456:0' => ['product_id' => 456, 'product_option_value_id' => null, 'quantity' => 3]];
        $this->mock(WSPayFormService::class, function ($mock): void {
            $mock->shouldReceive('handleCallback')->once()->andReturn(['status' => 'cancelled', 'paid' => false, 'callback_authorized' => true]);
            $mock->shouldReceive('handleCancellationEffects')->once();
        });
        $this->withSession(['front.cart.items' => $items, 'front.cart.coupon_code' => 'SAVE', 'front.checkout.last_order_id' => $order->id])
            ->get(route('checkout.wspay.cancel', ['orderNumber' => $order->order_number]))
            ->assertRedirect(route('cart.index'))->assertSessionHas('front.cart.items', $items)->assertSessionHas('front.cart.coupon_code', 'SAVE');
    }

    private function product(): Product
    {
        $product = Product::create(['code' => 'GIFT-TEST-PRODUCT', 'sku' => 'GIFT-TEST-PRODUCT', 'base_price' => 10, 'stock_qty' => 100, 'supplier_stock_qty' => 0, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Igračka za test', 'slug' => 'igracka-za-test']);

        return $product;
    }

    private function voucher(): GiftVoucher
    {
        return app(GiftVoucherService::class)->createManual($this->payload());
    }

    private function payload(): array
    {
        return [
            'amount' => 50, 'recipient_name' => 'Ana', 'recipient_email' => 'ana@example.test', 'sender_name' => 'Marko', 'message' => 'Sretan rođendan!',
            'customer_first_name' => 'Marko', 'customer_last_name' => 'Kupac', 'customer_email' => 'kupac@example.test', 'customer_phone' => '+385911234567',
            'billing_address_line_1' => 'Ulica 1', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_country_code' => 'HR',
            'payment_method_code' => 'bank', 'accept_terms' => '1',
        ];
    }
}
