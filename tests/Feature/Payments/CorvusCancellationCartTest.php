<?php

namespace Tests\Feature\Payments;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\User;
use App\Services\Front\StoreNotificationService;
use App\Services\Payments\CorvusPayFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CorvusCancellationCartTest extends TestCase
{
    use RefreshDatabase;

    private OrderStatus $pending;

    private OrderStatus $paid;

    private OrderStatus $cancelled;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false, 'session.same_site' => 'lax']);
        Http::preventStrayRequests();
        $this->pending = OrderStatus::create(['code' => 'new', 'name' => 'New', 'is_active' => true]);
        $this->paid = OrderStatus::create(['code' => 'paid', 'name' => 'Paid', 'is_paid' => true, 'is_active' => true]);
        $this->cancelled = OrderStatus::create(['code' => 'cancelled', 'name' => 'Cancelled', 'is_cancelled' => true, 'is_active' => true]);
        PaymentMethod::create([
            'code' => 'corvus', 'name' => 'CorvusPay', 'is_active' => true,
            'settings' => ['corvus_store_id' => '123', 'corvus_secret_key' => 'fixture-corvus-secret', 'corvus_mode' => 'test'],
        ]);
        $this->mock(StoreNotificationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendOrderNotification');
        });
    }

    public function test_generated_cancel_url_is_expiring_and_order_bound(): void
    {
        [$order] = $this->order();
        $form = app(CorvusPayFormService::class)->buildFormData($order);
        $url = $form['payload']['cancel_url'];
        $request = \Illuminate\Http\Request::create($url);

        $this->assertSame('checkout/corvus/cancel/'.$order->order_number, $request->path());
        $this->assertTrue(URL::hasValidSignature($request));
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, (int) $request->query('expires'), 1);
    }

    public function test_unsigned_provider_post_bounces_without_a_session_cookie_then_restores_original_buyer_session(): void
    {
        [$order, $product] = $this->order();
        $url = $this->cancelUrl($order);
        $this->withSession(['front.checkout.last_order_id' => $order->id]);
        $originalSessionId = session()->getId();
        $postback = $this->post($url, ['order_number' => $order->order_number, 'language' => 'hr']);
        $postback->assertStatus(303);
        $this->assertSame([], array_values(array_filter($postback->headers->getCookies(), fn ($cookie) => $cookie->getName() === config('session.cookie'))));
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('order_history', 0);

        $this->withCookie(config('session.cookie'), $originalSessionId)
            ->get($postback->headers->get('Location'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('front.cart.items', [$product->id.':0' => $this->line($product->id, 2)])
            ->assertSessionHas('front.cart.coupon_code', 'ORIGINAL')
            ->assertSessionHas('front.checkout.corvus_restored.'.$order->id, true);
        $this->assertSame($originalSessionId, session()->getId());
        $this->assertSame($this->cancelled->id, $order->fresh()->status_id);
        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertTrue(data_get($order->fresh()->payload, 'corvuspay.browser_cancellation_authorized'));
        $this->assertFalse((bool) data_get($order->fresh()->payload, 'corvuspay.callback_authorized'));
        $this->assertDatabaseCount('order_transactions', 0);
    }

    public function test_postback_body_cannot_replace_the_app_signature_expiry_or_redirect_target(): void
    {
        [$order] = $this->order();
        $url = $this->cancelUrl($order);
        parse_str(parse_url($url, PHP_URL_QUERY), $original);
        $response = $this->post($url, [
            'order_number' => $order->order_number, 'language' => 'hr',
            'signature' => 'forged', 'expires' => '1', 'return_url' => 'https://other.example.invalid/',
        ])->assertStatus(303);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $returned);
        $this->assertSame($original['signature'], $returned['signature']);
        $this->assertSame($original['expires'], $returned['expires']);
        $this->assertArrayNotHasKey('return_url', $returned);
        $this->assertSame(parse_url($url, PHP_URL_HOST), parse_url($response->headers->get('Location'), PHP_URL_HOST));
        $this->assertSame([], $response->headers->getCookies());
    }

    public function test_signed_cancel_without_an_existing_session_can_restore_the_cart(): void
    {
        [$order, $product] = $this->order();
        $this->get($this->cancelUrl($order).'&order_number='.$order->order_number.'&language=hr')
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('front.cart.items', [$product->id.':0' => $this->line($product->id, 2)])
            ->assertSessionHas('front.checkout.last_order_id', $order->id);
        $this->assertSame(3, $product->fresh()->stock_qty);
    }

    #[DataProvider('invalidReturns')]
    public function test_untrusted_or_mismatched_cancellation_never_changes_order_stock_or_cart(string $kind): void
    {
        [$order, $product] = $this->order();
        $other = $kind === 'different order' ? $this->order()[0] : null;
        $url = match ($kind) {
            'unsigned' => route('checkout.corvus.cancel', ['orderNumber' => $order->order_number]),
            'expired' => URL::temporarySignedRoute('checkout.corvus.cancel', now()->subMinute(), ['orderNumber' => $order->order_number]),
            'different order' => str_replace($other->order_number, $order->order_number, $this->cancelUrl($other)),
            'mismatched provider order' => $this->cancelUrl($order).'&order_number=OTHER-ORDER',
            'invalid signature' => $this->cancelUrl($order).'x',
        };
        $cart = ['999:0' => $this->line(999, 1)];
        $response = $this->withSession(['front.cart.items' => $cart, 'front.cart.coupon_code' => 'NEW'])
            ->get($url);
        $response->assertRedirect(route('cart.index'));
        $response->assertSessionHas('front.cart.items', $cart)->assertSessionHas('front.cart.coupon_code', 'NEW');
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('order_transactions', 0);
        $this->assertDatabaseCount('order_history', 0);
    }

    public static function invalidReturns(): array
    {
        return array_map(fn ($kind) => [$kind], ['unsigned', 'expired', 'different order', 'mismatched provider order', 'invalid signature']);
    }

    #[DataProvider('legacyBuyer')]
    public function test_unsigned_cancel_cannot_change_an_order_even_in_its_owners_session(bool $authenticated): void
    {
        $user = $authenticated ? User::factory()->create() : null;
        [$order, $product] = $this->order($user);
        if ($user) {
            $this->actingAs($user);
        } else {
            $this->withSession(['front.checkout.last_order_id' => $order->id]);
        }
        $this->get(route('checkout.corvus.cancel.static', ['order_number' => $order->order_number, 'language' => 'hr']))
            ->assertRedirect(route('cart.index'))
            ->assertSessionMissing('front.cart.items');
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertNull(data_get($order->fresh()->payload, 'corvuspay.cancel_restocked_at'));
        $this->assertDatabaseCount('order_transactions', 0);
        $this->assertDatabaseCount('order_history', 0);
    }

    public static function legacyBuyer(): array
    {
        return ['checkout session' => [false], 'authenticated owner' => [true]];
    }

    public function test_expired_cancel_capability_cannot_use_the_existing_checkout_session_as_a_fallback(): void
    {
        [$order, $product] = $this->order();
        $url = URL::temporarySignedRoute('checkout.corvus.cancel', now()->subMinute(), ['orderNumber' => $order->order_number]);
        $this->withSession(['front.checkout.last_order_id' => $order->id])->get($url)
            ->assertRedirect(route('cart.index'))->assertSessionMissing('front.cart.items');

        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('order_history', 0);
    }

    public function test_an_app_signed_static_cancel_url_cannot_authorize_an_unbound_order_number(): void
    {
        [$order, $product] = $this->order();
        $url = URL::temporarySignedRoute('checkout.corvus.cancel.static', now()->addHours(2));
        $cart = ['999:0' => $this->line(999, 2)];
        $this->withSession(['front.checkout.last_order_id' => $order->id, 'front.cart.items' => $cart])
            ->get($url.'&order_number='.$order->order_number.'&language=hr')
            ->assertRedirect(route('cart.index'))->assertSessionHas('front.cart.items', $cart);

        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('order_history', 0);
        $this->assertDatabaseCount('order_transactions', 0);
    }

    public function test_another_customer_cannot_cancel_an_unsigned_order(): void
    {
        [$order, $product] = $this->order(User::factory()->create());
        $this->actingAs(User::factory()->create())->get(route('checkout.corvus.cancel.static', ['order_number' => $order->order_number]))
            ->assertRedirect(route('cart.index'))->assertSessionMissing('front.cart.items');
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertSame(1, $product->fresh()->stock_qty);
    }

    public function test_restore_preserves_newer_quantities_variants_and_coupon_and_replay_cannot_replace_later_cart_edits(): void
    {
        [$order, $product] = $this->order();
        $option = Option::create(['code' => 'size', 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $oldVariant = $this->variant($product, $option, 'small');
        $newVariant = $this->variant($product, $option, 'large');
        $order->items()->first()->update(['product_option_value_id' => $oldVariant->id]);
        $current = [
            $product->id.':0' => $this->line($product->id, 4),
            $product->id.':'.$newVariant->id => $this->line($product->id, 1, $newVariant->id),
            '999:0' => $this->line(999, 1),
        ];
        $restored = $current + [$product->id.':'.$oldVariant->id => $this->line($product->id, 2, $oldVariant->id)];
        $url = $this->cancelUrl($order);
        $this->withSession(['front.cart.items' => $current, 'front.cart.coupon_code' => 'NEW'])
            ->get($url)->assertRedirect(route('cart.index'))
            ->assertSessionHas('front.cart.items', $restored)->assertSessionHas('front.cart.coupon_code', 'NEW');
        $this->assertSame(3, $oldVariant->fresh()->stock_qty);
        $this->assertSame(1, $newVariant->fresh()->stock_qty);
        $stockAfterCancel = $product->fresh()->stock_qty;
        $later = ['999:0' => $this->line(999, 3)];
        $this->withSession(['front.cart.items' => $later, 'front.cart.coupon_code' => 'LATER'])
            ->get($url)->assertRedirect(route('cart.index'))
            ->assertSessionHas('front.cart.items', $later)->assertSessionHas('front.cart.coupon_code', 'LATER');
        $this->assertSame($stockAfterCancel, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('order_history', 1);
    }

    public function test_a_late_success_after_browser_abandonment_does_not_pay_a_restocked_order(): void
    {
        [$order, $product] = $this->order();
        $this->get($this->cancelUrl($order))->assertRedirect(route('cart.index'));
        $input = ['approval_code' => 'APPROVED-LATE', 'language' => 'hr', 'order_number' => $order->order_number];
        $input['signature'] = hash_hmac('sha256', 'approval_codeAPPROVED-LATElanguagehrorder_number'.$order->order_number, 'fixture-corvus-secret');
        $result = app(CorvusPayFormService::class)->handleCallback($order, $input, 'success');

        $this->assertSame('late_success_after_cancel', $result['status']);
        $this->assertFalse($result['paid']);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame($this->cancelled->id, $order->fresh()->status_id);
        $this->assertSame(3, $product->fresh()->stock_qty);
    }

    public function test_a_valid_provider_payment_approval_cannot_be_replayed_as_cancellation(): void
    {
        [$order, $product] = $this->order();
        $input = ['approval_code' => 'APPROVED-PAYMENT', 'language' => 'hr', 'order_number' => $order->order_number];
        $input['signature'] = hash_hmac('sha256', 'approval_codeAPPROVED-PAYMENTlanguagehrorder_number'.$order->order_number, 'fixture-corvus-secret');
        $cart = ['999:0' => $this->line(999, 2)];
        $this->withSession(['front.checkout.last_order_id' => $order->id, 'front.cart.items' => $cart])
            ->get(route('checkout.corvus.cancel', ['orderNumber' => $order->order_number, ...$input]))
            ->assertRedirect(route('cart.index'))->assertSessionHas('front.cart.items', $cart);

        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertNull(data_get($order->fresh()->payload, 'corvuspay.cancel_restocked_at'));
        $this->assertDatabaseCount('order_history', 0);
        $this->assertDatabaseCount('order_transactions', 0);
    }

    public function test_old_cancel_cannot_replace_a_newer_checkout_pointer_or_cart(): void
    {
        [$order] = $this->order();
        [$newer] = $this->order();
        $current = ['999:0' => $this->line(999, 1)];
        $this->withSession(['front.checkout.last_order_id' => $newer->id, 'front.checkout.last_order_number' => $newer->order_number, 'front.cart.items' => $current])
            ->get($this->cancelUrl($order))->assertRedirect(route('cart.index'))
            ->assertSessionHas('front.checkout.last_order_id', $newer->id)
            ->assertSessionHas('front.checkout.last_order_number', $newer->order_number)
            ->assertSessionHas('front.cart.items', $current);
    }

    public function test_paid_order_and_success_endpoint_cannot_be_changed_by_a_browser_cancellation_capability(): void
    {
        [$order, $product] = $this->order();
        $order->update(['paid_at' => now(), 'status_id' => $this->paid->id]);
        $current = ['999:0' => $this->line(999, 1)];
        $url = $this->cancelUrl($order);
        $this->withSession(['front.cart.items' => $current])->get($url)
            ->assertRedirect(route('cart.index'))->assertSessionHas('front.cart.items', $current);
        $this->assertSame($this->paid->id, $order->fresh()->status_id);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertNull(data_get($order->fresh()->payload, 'corvuspay.cancel_restocked_at'));

        [$pending] = $this->order();
        $success = str_replace('/cancel/', '/success/', $this->cancelUrl($pending));
        $this->get($success.'&order_number='.$pending->order_number.'&approval_code=FORGED&language=hr')
            ->assertRedirect(route('checkout.success', ['orderNumber' => $pending->order_number]));
        $this->assertNull($pending->fresh()->paid_at);
        $this->assertSame($this->pending->id, $pending->fresh()->status_id);
        $this->assertDatabaseCount('order_transactions', 0);
    }

    private function cancelUrl(Order $order): string
    {
        return URL::temporarySignedRoute('checkout.corvus.cancel', now()->addHours(2), ['orderNumber' => $order->order_number]);
    }

    private function order(?User $user = null): array
    {
        $product = Product::create(['code' => 'CORVUS-'.bin2hex(random_bytes(4)), 'name' => 'Test product', 'base_price' => 5, 'is_active' => true, 'stock_qty' => 1]);
        $order = Order::create([
            'order_number' => 'AG-CANCEL-'.bin2hex(random_bytes(4)), 'status_id' => $this->pending->id, 'user_id' => $user?->id,
            'source' => 'web', 'locale' => 'hr', 'currency_code' => 'EUR', 'currency_rate' => 1,
            'customer_name' => 'Test buyer', 'customer_email' => 'buyer@example.invalid',
            'payment_method_code' => 'corvus', 'payment_method_name' => 'CorvusPay',
            'subtotal' => 10, 'grand_total' => 10, 'placed_at' => now(), 'payload' => ['coupon_code' => 'ORIGINAL'],
        ]);
        $order->items()->create(['product_id' => $product->id, 'name' => 'Test product', 'quantity' => 2, 'unit_price' => 5, 'line_total' => 10, 'sort_order' => 1]);

        return [$order, $product];
    }

    private function line(int $productId, int $quantity, ?int $optionId = null): array
    {
        return ['product_id' => $productId, 'product_option_value_id' => $optionId, 'quantity' => $quantity];
    }

    private function variant(Product $product, Option $option, string $code): ProductOptionValue
    {
        $value = OptionValue::create(['option_id' => $option->id, 'code' => $code, 'is_active' => true]);

        return ProductOptionValue::create([
            'product_id' => $product->id, 'option_value_id' => $value->id,
            'combination_hash' => 'single:'.$value->id, 'stock_qty' => 1, 'is_active' => true,
        ]);
    }
}
