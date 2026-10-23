<?php

namespace Tests\Feature\Sales;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\GiftVoucherRedemption;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\GiftVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GiftVoucherServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false, 'commerce.local_safe_mode' => true, 'gift_vouchers.enabled' => true, 'gift_vouchers.emails_enabled' => false]);
        app()->setLocale('hr');
        Mail::fake();
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'U obradi', 'is_default' => true, 'is_paid' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'pending', 'name' => 'Čeka uplatu', 'is_paid' => false, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Dostava', 'price' => 5, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Uplata', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    public function test_unpaid_purchase_never_issues_even_with_an_imported_paid_default_status(): void
    {
        $service = app(GiftVoucherService::class);
        $order = $service->purchase($this->purchaseData());
        $voucher = GiftVoucher::query()->sole();
        $service->syncOrder($order);
        $this->assertSame('pending', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->code);
        $this->assertNull($voucher->issued_at);
        $this->assertNull($order->paid_at);
        $this->assertFalse($order->status->is_paid);
        $this->assertNull($order->items()->sole()->product_id);
        $this->assertSame('0.00', $order->shipping_total);
        $this->assertSame('0.00', $order->tax_total);
        Mail::assertNothingSent();
    }

    public function test_paid_purchase_issues_one_encrypted_code_and_never_changes_it_on_replay(): void
    {
        $service = app(GiftVoucherService::class);
        $order = $service->purchase($this->purchaseData());
        $order->forceFill(['paid_at' => now()])->save();
        $voucher = GiftVoucher::query()->sole();
        $this->assertSame(GiftVoucher::STATUS_ACTIVE, $voucher->status);
        $this->assertNotNull($voucher->issued_at);
        $this->assertStringStartsWith('LIBER-', $voucher->code);
        $this->assertNotSame($voucher->code, $voucher->code_ciphertext);
        $this->assertSame(hash('sha256', $voucher->code), $voucher->code_hash);
        $this->assertArrayNotHasKey('code_ciphertext', $voucher->toArray());
        $code = $voucher->code;
        $service->syncOrder($order);
        $service->confirmPurchasePayment($order);
        $this->assertSame($code, $voucher->fresh()->code);
        $this->assertDatabaseCount('gift_vouchers', 1);
    }

    public function test_purchase_preserves_physical_cart_and_includes_only_allowed_payment_methods(): void
    {
        PaymentMethod::query()->create(['code' => 'cod', 'name' => 'Pouzeće', 'provider' => 'cod', 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'free_checkout', 'name' => 'Besplatno', 'provider' => 'free', 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'wspay', 'name' => 'Kartica bez postavki', 'provider' => 'wspay', 'is_active' => true]);
        $cart = app(CartService::class);
        $cart->add($this->product(), 2);
        $before = $cart->raw();
        $service = app(GiftVoucherService::class);
        $this->assertSame(['bank'], $service->purchasePaymentMethods(50)->pluck('code')->all());
        $service->purchase($this->purchaseData());
        $this->assertSame($before, $cart->raw());
        $this->expectException(ValidationException::class);
        $service->purchase($this->purchaseData(['payment_method_code' => 'cod']));
    }

    public function test_purchase_totals_and_order_use_the_same_fee_rounding(): void
    {
        $method = PaymentMethod::query()->where('code', 'bank')->first();
        $method->update(['fee_type' => 'percent', 'fee_value' => 2.55]);
        $service = app(GiftVoucherService::class);
        $this->assertSame(['amount' => 50.0, 'payment_fee_total' => 1.28, 'grand_total' => 51.28], $service->purchaseTotals(50, $method));
        $order = $service->purchase($this->purchaseData());
        $this->assertSame('51.28', $order->grand_total);
        $this->assertSame('50.00', GiftVoucher::query()->sole()->initial_amount);
    }

    public function test_partial_reservation_is_idempotent_and_a_second_order_cannot_overspend(): void
    {
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData());
        $first = $this->order();
        $row = $service->reserveForOrder($first, $voucher->code, 30.05);
        $this->assertSame('19.95', $voucher->fresh()->balance);
        $this->assertSame($row->id, $service->reserveForOrder($first, $voucher->code, 30.05)->id);
        $this->assertSame('19.95', $voucher->fresh()->balance);
        try {
            $service->reserveForOrder($this->order(), $voucher->code, 20);
            $this->fail('Potrošnja iznad salda mora biti odbijena.');
        } catch (ValidationException) {
            $this->assertSame('19.95', $voucher->fresh()->balance);
            $this->assertDatabaseCount('gift_voucher_redemptions', 1);
        }
        $first->forceFill(['paid_at' => now()])->save();
        $this->assertSame(GiftVoucherRedemption::STATUS_REDEEMED, $row->fresh()->status);
        $service->syncOrder($first);
        $this->assertSame('19.95', $voucher->fresh()->balance);
    }

    public function test_zero_remaining_reservation_is_idempotent_and_settles_as_exhausted(): void
    {
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData());
        $order = $this->order();
        $redemption = $service->reserveForOrder($order, $voucher->code, 50);
        $this->assertSame($redemption->id, $service->reserveForOrder($order, $voucher->code, 50)->id);
        $order->forceFill(['paid_at' => now()])->save();
        $this->assertSame(GiftVoucher::STATUS_EXHAUSTED, $voucher->fresh()->status);
        $this->assertSame('0.00', $voucher->fresh()->balance);
        $this->assertSame($redemption->id, $service->reserveForOrder($order, $voucher->code, 50)->id);
    }

    public function test_expired_reservations_release_exactly_once_and_do_not_release_paid_orders(): void
    {
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData());
        $first = $this->order();
        $paid = $this->order();
        $service->reserveForOrder($first, $voucher->code, 20);
        $paidRow = $service->reserveForOrder($paid, $voucher->code, 10);
        $paid->forceFill(['paid_at' => now()])->save();
        $this->travel(181)->minutes();
        $this->assertSame(1, $service->releaseExpiredReservations());
        $this->assertSame(0, $service->releaseExpiredReservations());
        $this->assertSame('40.00', $voucher->fresh()->balance);
        $this->assertSame(GiftVoucherRedemption::STATUS_REDEEMED, $paidRow->fresh()->status);
    }

    public function test_unpaid_cancel_releases_but_paid_cancel_requires_separate_refund(): void
    {
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData());
        $cancelled = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => true, 'is_active' => true]);
        $unpaid = $this->order();
        $service->reserveForOrder($unpaid, $voucher->code, 20);
        $unpaid->update(['status_id' => $cancelled->id]);
        $this->assertSame('50.00', $voucher->fresh()->balance);
        $paid = $this->order();
        $row = $service->reserveForOrder($paid, $voucher->code, 20);
        $paid->update(['paid_at' => now()]);
        $paid->update(['status_id' => $cancelled->id]);
        $this->assertSame('30.00', $voucher->fresh()->balance);
        $this->assertSame(GiftVoucherRedemption::STATUS_REDEEMED, $row->fresh()->status);
    }

    public function test_disabled_expired_and_cancelled_vouchers_cannot_be_applied(): void
    {
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData());
        $this->assertTrue($service->applyCode('  '.strtolower($voucher->code).'  '));
        $this->assertSame($voucher->code, $service->currentCode());
        $service->setEnabled($voucher, false);
        $this->assertFalse($service->applyCode($voucher->code));
        $this->assertSame(0.0, $service->quote(50)['amount']);
        $service->setEnabled($voucher, true);
        $voucher->fresh()->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($service->applyCode($voucher->code));
        $service->clearCode();
        $this->assertSame('', $service->currentCode());
    }

    public function test_partial_checkout_preserves_tax_shipping_discount_and_reserves_final_tender(): void
    {
        TaxRate::query()->create(['code' => 'vat', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_default' => true, 'is_active' => true]);
        app(CartService::class)->add($this->product(), 5);
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData(['amount' => 30]));
        $service->applyCode($voucher->code);
        $order = app(CheckoutService::class)->placeOrder($this->checkoutData());
        $this->assertSame('50.00', $order->subtotal);
        $this->assertSame('12.50', $order->tax_total);
        $this->assertSame('5.00', $order->shipping_total);
        $this->assertSame('0.00', $order->discount_total);
        $this->assertSame('37.50', $order->grand_total);
        $this->assertSame(67.5, data_get($order->payload, 'original_total'));
        $this->assertSame('30.00', GiftVoucherRedemption::query()->sole()->amount);
        $this->assertSame('reserved', GiftVoucherRedemption::query()->sole()->status);
        $this->assertNull($order->paid_at);
    }

    public function test_full_checkout_uses_internal_payment_and_settles_only_actual_invoice_amount(): void
    {
        TaxRate::query()->create(['code' => 'vat', 'name' => 'PDV', 'rate' => 25, 'rate_type' => 'percent', 'is_default' => true, 'is_active' => true]);
        app(CartService::class)->add($this->product(), 2);
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData());
        $service->applyCode($voucher->code);
        $order = app(CheckoutService::class)->placeOrder($this->checkoutData(['payment_method_code' => 'gift_voucher']));
        $this->assertSame('gift_voucher', $order->payment_method_code);
        $this->assertSame('0.00', $order->grand_total);
        $this->assertSame('5.00', $order->tax_total);
        $this->assertSame('20.00', $voucher->fresh()->balance);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('redeemed', GiftVoucherRedemption::query()->sole()->status);
    }

    public function test_stale_balance_rejects_checkout_and_rolls_back_all_order_and_stock_writes(): void
    {
        $product = $this->product();
        app(CartService::class)->add($product, 1);
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData());
        $service->applyCode($voucher->code);
        $voucher->update(['status' => 'disabled']);
        try {
            app(CheckoutService::class)->placeOrder($this->checkoutData());
            $this->fail('Promijenjeni bon mora zaustaviti naplatu.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('gift_voucher_code', $e->errors());
            $this->assertDatabaseCount('orders', 0);
            $this->assertSame(100, $product->fresh()->stock_qty);
        }
    }

    public function test_admin_confirmation_sets_payment_without_shipping_and_is_idempotent(): void
    {
        $service = app(GiftVoucherService::class);
        $this->actingAs(\App\Models\User::factory()->create());
        $order = $service->purchase($this->purchaseData());
        $status = $order->status_id;
        $before = $order->history()->count();
        $service->confirmPurchasePayment($order);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame($status, $order->fresh()->status_id);
        $this->assertSame('active', GiftVoucher::query()->sole()->status);
        $this->assertSame($before + 1, $order->history()->count());
        $service->confirmPurchasePayment($order);
        $this->assertSame($before + 1, $order->history()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'payment_confirmed', 'subject_id' => $order->id, 'causer_id' => auth()->id()]);
    }

    public function test_signed_gateway_payment_issues_voucher_and_forged_payment_never_does(): void
    {
        PaymentMethod::query()->create(['code' => 'wspay', 'name' => 'Kartica', 'provider' => 'wspay', 'is_active' => true, 'settings' => ['wspay_shop_id' => 'test-shop', 'wspay_secret_key' => 'test-secret']]);
        $service = app(GiftVoucherService::class);
        $gateway = app(\App\Services\Payments\WSPayFormService::class);
        $order = $service->purchase($this->purchaseData(['payment_method_code' => 'wspay']));
        $input = ['ShoppingCartID' => $order->order_number, 'Success' => '1', 'ApprovalCode' => '123456', 'WsPayOrderId' => 'gift-test-transaction'];
        $forged = $gateway->handleCallback($order, $input + ['Signature' => 'invalid'], 'return');
        $this->assertFalse($forged['paid']);
        $this->assertNull(GiftVoucher::query()->sole()->code);
        $input['Signature'] = hash('sha512', 'test-shop'.'test-secret'.$order->order_number.'test-secret'.'1'.'test-secret'.'123456'.'test-secret');
        $this->assertTrue($gateway->handleCallback($order, $input, 'return')['paid']);
        $voucher = GiftVoucher::query()->sole();
        $this->assertNotNull($voucher->code);
        $this->assertSame('active', $voucher->status);
        $code = $voucher->code;
        $gateway->handleCallback($order, $input, 'return');
        $this->assertSame($code, $voucher->fresh()->code);
        $this->assertDatabaseCount('order_transactions', 1);
    }

    public function test_cancelled_purchase_cannot_issue_or_reactivate_its_voucher(): void
    {
        $service = app(GiftVoucherService::class);
        $order = $service->purchase($this->purchaseData());
        $cancelled = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => true, 'is_active' => true]);
        $order->update(['status_id' => $cancelled->id]);
        $voucher = GiftVoucher::query()->sole();
        $this->assertSame('cancelled', $voucher->status);
        $this->assertNull($voucher->code);
        $this->expectException(ValidationException::class);
        $service->confirmPurchasePayment($order);
    }

    public function test_late_verified_gateway_charge_is_preserved_with_attention_when_released_balance_was_spent(): void
    {
        PaymentMethod::query()->create(['code' => 'wspay', 'name' => 'Kartica', 'provider' => 'wspay', 'is_active' => true, 'settings' => ['wspay_shop_id' => 'test-shop', 'wspay_secret_key' => 'test-secret']]);
        app(CartService::class)->add($this->product(), 5);
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData(['amount' => 30]));
        $service->applyCode($voucher->code);
        $order = app(CheckoutService::class)->placeOrder($this->checkoutData(['payment_method_code' => 'wspay']));
        $original = GiftVoucherRedemption::query()->where('order_id', $order->id)->sole();
        $this->assertSame('25.00', $order->grand_total);
        $this->travel(181)->minutes();
        $this->assertSame(1, $service->releaseExpiredReservations());
        $other = $this->order();
        $spent = $service->reserveForOrder($other, $voucher->code, 30);
        $other->update(['paid_at' => now()]);
        $gateway = app(\App\Services\Payments\WSPayFormService::class);
        $input = ['ShoppingCartID' => $order->order_number, 'Success' => '1', 'ApprovalCode' => '654321', 'WsPayOrderId' => 'late-gift-charge'];
        $input['Signature'] = hash('sha512', 'test-shop'.'test-secret'.$order->order_number.'test-secret'.'1'.'test-secret'.'654321'.'test-secret');
        $result = $gateway->handleCallback($order, $input, 'return');
        $this->assertTrue($result['paid']);
        $this->assertTrue($result['newly_paid']);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('25.00', $order->transactions()->sole()->amount);
        $this->assertSame('approved', $order->transactions()->sole()->status);
        $this->assertSame('released', $original->fresh()->status);
        $this->assertNull($original->fresh()->redeemed_at);
        $this->assertSame('redeemed', $spent->fresh()->status);
        $this->assertSame('0.00', $voucher->fresh()->balance);
        $attention = data_get($order->fresh()->payload, 'gift_voucher_settlement');
        $this->assertSame('attention', $attention['status']);
        $this->assertEquals(30.0, $attention['amount']);
        $this->assertSame('insufficient_balance', $attention['reason']);
        $historyCount = $order->history()->count();
        $this->travel(1)->minutes();
        $this->assertFalse($gateway->handleCallback($order->fresh(), $input, 'return')['newly_paid']);
        $service->syncOrder($order);
        $this->assertSame($attention, data_get($order->fresh()->payload, 'gift_voucher_settlement'));
        $this->assertSame($historyCount, $order->history()->count());
        $this->assertSame('0.00', $voucher->fresh()->balance);
        $this->assertDatabaseCount('gift_voucher_redemptions', 2);
        $this->assertDatabaseCount('order_transactions', 1);
        $admin = \App\Models\User::factory()->create();
        \Silber\Bouncer\BouncerFacade::assign('admin')->to($admin);
        $this->actingAs($admin)->get('/admin/orders/'.$order->id.'/show')->assertOk()
            ->assertSee('Poklon bon: potreban je pregled')
            ->assertSee('Nepokriveni iznos bona:')
            ->assertSee('30,00 €')
            ->assertSee('Uplata je zaprimljena.');
    }

    public function test_late_payment_can_reacquire_available_released_balance_exactly_once(): void
    {
        $service = app(GiftVoucherService::class);
        $voucher = $service->createManual($this->purchaseData());
        $order = $this->order();
        $row = $service->reserveForOrder($order, $voucher->code, 20);
        $this->travel(181)->minutes();
        $service->releaseExpiredReservations();
        $order->update(['paid_at' => now()]);
        $this->assertSame('redeemed', $row->fresh()->status);
        $this->assertSame('30.00', $voucher->fresh()->balance);
        $this->assertSame('settled', data_get($order->fresh()->payload, 'gift_voucher_settlement.status'));
        $service->syncOrder($order);
        $this->assertSame('30.00', $voucher->fresh()->balance);
        $this->assertDatabaseCount('gift_voucher_redemptions', 1);
    }

    private function product(): Product
    {
        $code = 'GV-'.Str::random(8);
        $product = Product::query()->create(['code' => $code, 'sku' => $code, 'base_price' => 10, 'stock_qty' => 100, 'supplier_stock_qty' => 0, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => strtolower($code)]);

        return $product;
    }

    private function order(): Order
    {
        return Order::query()->create(['order_number' => 'TEST-'.Str::random(12), 'customer_name' => 'Kupac', 'customer_email' => 'buyer@example.test', 'grand_total' => 50, 'status_id' => OrderStatus::query()->where('code', 'pending')->value('id')]);
    }

    private function purchaseData(array $overrides = []): array
    {
        return array_replace(['amount' => 50, 'recipient_name' => 'Primatelj', 'recipient_email' => 'recipient@example.test', 'sender_name' => 'Pošiljatelj', 'message' => 'Sretan rođendan!', 'customer_first_name' => 'Test', 'customer_last_name' => 'Kupac', 'customer_email' => 'buyer@example.test', 'payment_method_code' => 'bank'], $overrides);
    }

    private function checkoutData(array $overrides = []): array
    {
        return array_replace(['customer_email' => 'buyer@example.test', 'customer_phone' => '+385911234567', 'billing_first_name' => 'Test', 'billing_last_name' => 'Kupac', 'billing_address_line_1' => 'Test 1', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb', 'billing_country_code' => 'HR', 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank'], $overrides);
    }
}
