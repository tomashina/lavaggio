<?php

namespace Tests\Feature\Integrations;

use App\Mail\WatchlineAutomationMail;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Support\Comment;
use App\Models\Integrations\Watchline\AbandonedCart;
use App\Models\Integrations\Watchline\MessageDelivery;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Services\Front\CartService;
use App\Services\Integrations\Watchline\WatchlineMessageAutomationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class WatchlineMessageAutomationTest extends TestCase
{
    use RefreshDatabase;

    private WatchlineMessageAutomationService $automations;

    private OrderStatus $completed;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'storefront_cache.enabled' => false, 'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'commerce.local_safe_mode' => true]);
        Carbon::setTestNow('2026-10-01 12:00:00');
        Mail::fake();
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Watchline', 'store_email_enabled' => false]);
        $this->completed = OrderStatus::updateOrCreate(['code' => 'herrera-oc-status-5'], ['name' => 'Dovršeno', 'is_paid' => true, 'is_cancelled' => false, 'is_active' => true]);
        $this->automations = app(WatchlineMessageAutomationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_verified_defaults_and_activation_cutoff_cannot_be_backdated_by_configuration(): void
    {
        $this->assertFalse($this->automations->options('reviews')['enabled']);
        $this->assertSame(7, $this->automations->options('reviews')['delay_days']);
        $this->assertSame(60, $this->automations->options('abandoned')['delay_minutes']);
        $this->assertSame(7, $this->automations->options('abandoned')['template_id']);
        $settings = $this->automations->configure('reviews', ['enabled' => true, 'activated_at' => '2000-01-01', 'subject' => "Naslov\r\nPoruke"]);
        $this->assertSame(now()->toIso8601String(), $settings['activated_at']);
        $this->assertSame('NaslovPoruke', $settings['subject']);
        $this->automations->configure('reviews', ['enabled' => false]);
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->assertSame(now()->toIso8601String(), $this->automations->configure('reviews', ['enabled' => true])['activated_at']);
        Mail::assertNothingSent();
    }

    public function test_review_preview_counts_only_new_eligible_unreviewed_orders_and_never_sends_or_claims(): void
    {
        $this->enableReviews();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $product = $this->product('review-watch');
        $this->order('new', $product);
        $this->order('imported', $product, ['source' => 'opencart_import']);
        $this->order('old-native', $product, ['placed_at' => '2026-09-30 12:00:00']);
        $this->order('recent', $product, ['placed_at' => '2026-10-09 12:00:00']);
        $cancelled = OrderStatus::create(['code' => 'cancelled-fixture', 'name' => 'Otkazano', 'is_cancelled' => true, 'is_active' => true]);
        $this->order('cancelled', $product, ['status_id' => $cancelled->id]);
        $reviewed = $this->order('reviewed', $product);
        Comment::create(['commentable_type' => Product::class, 'commentable_id' => $product->id, 'locale' => 'hr',
            'author_name' => 'Fixture', 'author_email' => $reviewed->customer_email, 'body' => 'Već ostavljena recenzija',
            'rating' => 5, 'status' => Comment::STATUS_APPROVED]);
        $report = $this->automations->preview('reviews');
        $this->assertSame(1, $report['eligible']);
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('store_email_disabled', $report['reasons']);
        $this->assertContains('local_safe_mode', $report['reasons']);
        $this->assertSame(0, $report['sent']);
        $this->assertDatabaseCount('watchline_message_deliveries', 0);
        $this->assertDatabaseCount('catalog_actions', 0);
        $this->assertStringNotContainsString('@example.test', json_encode($report));
        Mail::assertNothingSent();
    }

    public function test_safe_mode_and_disabled_store_email_each_block_a_live_run(): void
    {
        $this->enableReviews();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->order('blocked', $this->product('blocked-watch'));
        app(SystemSettingsService::class)->put('store_email_enabled', true);
        $this->assertContains('local_safe_mode', $this->automations->run('reviews', false)['reasons']);
        config(['commerce.local_safe_mode' => false]);
        app(SystemSettingsService::class)->put('store_email_enabled', false);
        $this->assertContains('store_email_disabled', $this->automations->run('reviews', false)['reasons']);
        $this->assertDatabaseCount('watchline_message_deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_local_environment_itself_blocks_direct_live_runs_even_when_safe_mode_is_off(): void
    {
        $this->enableReviews();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->order('local-blocked', $this->product('local-blocked-watch'));
        $this->permitFakeDelivery();
        $this->app['env'] = 'local';
        $report = $this->automations->run('reviews', false);
        $this->assertSame('blocked', $report['status']);
        $this->assertContains('local_environment', $report['reasons']);
        $this->assertSame(0, $report['sent']);
        $this->assertDatabaseCount('watchline_message_deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_disabled_abandoned_job_does_not_capture_contact_or_modify_carts(): void
    {
        $product = $this->product('disabled-capture');
        $this->withSession(['front.cart.items' => [['product_id' => $product->id, 'quantity' => 1]]])
            ->postJson(route('watchline.messages.capture'), ['email' => 'disabled-fixture@example.test', 'consent' => true])
            ->assertOk()->assertJsonPath('captured', false)->assertSessionMissing('watchline.cart_contact');
        $this->assertDatabaseCount('watchline_abandoned_carts', 0);
        $this->get(route('checkout.create'))->assertOk()->assertDontSee('watchline-cart-contact.js', false);
        $this->assertDatabaseCount('watchline_abandoned_carts', 0);
        Mail::assertNothingSent();
    }

    public function test_review_delivery_is_deduplicated_and_private_and_uses_native_product_review_links(): void
    {
        $this->enableReviews();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $order = $this->order('ready', $this->product('ready-watch'));
        $this->permitFakeDelivery();
        $this->assertSame('preview', $this->automations->preview('reviews')['status']);
        Mail::assertNothingSent();
        $report = $this->automations->run('reviews', false);
        $this->assertSame('completed', $report['status']);
        $this->assertSame(1, $report['sent']);
        Mail::assertSent(WatchlineAutomationMail::class, function ($mail) use ($order): bool {
            $this->assertTrue($mail->hasTo($order->customer_email));
            $this->assertSame(route('products.show', ['slug' => 'ready-watch']).'#product-comments', $mail->messageData['products'][0]['url']);
            $this->assertStringContainsString('watchline-logo-on-dark.svg', $mail->render());

            return true;
        });
        $this->assertSame(0, $this->automations->run('reviews', false)['sent']);
        Mail::assertSentCount(1);
        $delivery = MessageDelivery::firstOrFail();
        $this->assertNotSame($order->customer_email, DB::table('watchline_message_deliveries')->value('recipient'));
        $this->assertArrayNotHasKey('recipient', $delivery->toArray());
        $this->assertStringNotContainsString($order->customer_email, json_encode($report));
    }

    public function test_transport_failure_does_not_trigger_automatic_replay_and_never_stores_raw_error_text(): void
    {
        $this->enableReviews();
        Carbon::setTestNow('2026-10-10 12:00:00');
        $this->order('failed', $this->product('failed-watch'));
        $this->permitFakeDelivery();
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('private transport details fixture'));
        $report = $this->automations->run('reviews', false);
        $this->assertSame('failed', $report['status']);
        $this->assertSame(1, $report['failed']);
        $this->assertSame('RuntimeException', MessageDelivery::firstOrFail()->error_code);
        $this->assertSame(0, $this->automations->run('reviews', false)['eligible']);
        $this->assertStringNotContainsString('private transport details', json_encode($report));
    }

    public function test_guest_cart_capture_requires_opt_in_encrypts_contact_and_does_not_reset_inactivity_on_page_views(): void
    {
        $this->automations->configure('abandoned', ['enabled' => true]);
        $product = $this->product('cart-watch');
        $items = [['product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => 2]];
        $this->withSession(['front.cart.items' => $items]);
        $this->postJson(route('watchline.messages.capture'), ['email' => 'cart-fixture@example.test', 'consent' => false])->assertOk();
        $this->assertDatabaseCount('watchline_abandoned_carts', 0);
        $this->postJson(route('watchline.messages.capture'), ['email' => 'cart-fixture@example.test', 'consent' => true])->assertOk();
        $cart = AbandonedCart::firstOrFail();
        $this->assertNotNull($cart->consent_at);
        $this->assertNotSame('cart-fixture@example.test', DB::table('watchline_abandoned_carts')->value('email'));
        $activity = $cart->last_activity_at->toIso8601String();
        Carbon::setTestNow('2026-10-01 12:30:00');
        $this->get(route('cart.index'))->assertOk();
        $this->assertSame($activity, $cart->fresh()->last_activity_at->toIso8601String());
        $this->assertSame(0, $this->automations->preview('abandoned')['eligible']);
        Carbon::setTestNow('2026-10-01 13:01:00');
        $this->assertSame(1, $this->automations->preview('abandoned')['eligible']);
        $this->postJson(route('watchline.messages.capture'), ['email' => 'cart-fixture@example.test', 'consent' => false])->assertOk();
        $this->assertNull($cart->fresh()->consent_at);
        $this->assertSame(0, $this->automations->preview('abandoned')['eligible']);
        Mail::assertNothingSent();
    }

    public function test_abandoned_delivery_creates_a_native_targeted_coupon_and_signed_restore_and_unsubscribe_work(): void
    {
        $this->automations->configure('abandoned', ['enabled' => true]);
        $product = $this->product('coupon-watch');
        $outside = $this->product('outside-watch');
        $cart = $this->cart($product);
        Carbon::setTestNow('2026-10-01 13:01:00');
        $preview = $this->automations->preview('abandoned');
        $this->assertSame(1, $preview['eligible']);
        $this->assertDatabaseCount('catalog_actions', 0);
        $this->permitFakeDelivery();
        $this->assertSame(1, $this->automations->run('abandoned', false)['sent']);
        $mail = Mail::sent(WatchlineAutomationMail::class)->first();
        $coupon = CatalogAction::firstOrFail();
        $this->assertEquals(10, $coupon->discount_value);
        $this->assertSame([$product->id], $coupon->targets->pluck('target_id')->all());
        $this->assertSame(1, $coupon->usage_limit);
        $this->assertTrue($coupon->ends_at->equalTo(now()->addDays(7)));
        $this->assertSame(2, $mail->messageData['products'][0]['quantity']);
        $this->get(route('watchline.messages.recover', ['cart' => $cart->id]))->assertForbidden();
        $this->get($mail->messageData['action_url'])->assertRedirect(route('cart.index'));
        $this->assertNotNull($cart->fresh()->recovered_at);
        $nativeCart = app(CartService::class);
        $nativeCart->add($outside, 1);
        $this->assertSame($coupon->coupon_code, $nativeCart->couponCode());
        $this->assertEquals(20, $nativeCart->summary()['cart_discount_total'], 'The10% coupon applies to two captured100EUR items, not the outside product.');
        $this->assertSame(0, $this->automations->run('abandoned', false)['sent']);
        $this->get($mail->messageData['unsubscribe_url'])->assertOk();
        $this->assertNull($cart->fresh()->consent_at);
        $this->assertDatabaseHas('watchline_message_suppressions', ['job' => 'abandoned', 'email_hash' => $cart->email_hash]);
        $this->get(str_replace('abandoned', 'reviews', $mail->messageData['unsubscribe_url']))->assertForbidden();
        Mail::assertSentCount(1);
    }

    public function test_converted_closed_unconsented_and_historical_carts_are_excluded(): void
    {
        $this->automations->configure('abandoned', ['enabled' => true]);
        $product = $this->product('excluded-watch');
        $cart = $this->cart($product);
        $this->order('cart-conversion', $product, ['customer_email' => $cart->email, 'placed_at' => now()]);
        $this->assertNotNull($cart->fresh()->converted_at);
        $this->cart($product, ['consent_at' => null]);
        $this->cart($product, ['closed_at' => now()]);
        $this->cart($product, ['created_at' => '2026-09-30 12:00:00']);
        Carbon::setTestNow('2026-10-01 14:00:00');
        $this->assertSame(0, $this->automations->preview('abandoned')['eligible']);
        Mail::assertNothingSent();
    }

    public function test_review_unsubscribe_and_batch_limit_do_not_starve_later_eligible_orders(): void
    {
        $this->enableReviews();
        $this->automations->configure('reviews', ['batch_size' => 1]);
        Carbon::setTestNow('2026-10-10 12:00:00');
        $product = $this->product('batch-watch');
        $suppressed = $this->order('suppressed', $product);
        $this->order('batch-ready', $product);
        $url = URL::temporarySignedRoute('watchline.messages.unsubscribe', now()->addDay(),
            ['job' => 'reviews', 'hash' => WatchlineMessageAutomationService::emailHash($suppressed->customer_email)]);
        $this->get($url)->assertOk();
        $this->assertSame(1, $this->automations->preview('reviews')['eligible']);
        $this->permitFakeDelivery();
        $this->assertSame(1, $this->automations->run('reviews', false)['sent']);
        Mail::assertSent(WatchlineAutomationMail::class, fn ($mail): bool => $mail->hasTo('batch-ready@example.test'));
    }

    public function test_registered_opted_in_cart_is_captured_by_middleware_and_keeps_identity_when_session_rotates(): void
    {
        $this->automations->configure('abandoned', ['enabled' => true]);
        $user = User::factory()->create();
        $user->profile()->create(['newsletter_opt_in' => true]);
        $product = $this->product('registered-cart');
        $this->actingAs($user)->withSession(['front.cart.items' => [['product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => 1]]])
            ->get(route('cart.index'))->assertOk();
        $this->assertDatabaseCount('watchline_abandoned_carts', 1);
        $cartId = AbandonedCart::firstOrFail()->id;
        session()->regenerate();
        $this->get(route('cart.index'))->assertOk();
        $this->assertDatabaseCount('watchline_abandoned_carts', 1);
        $this->assertSame($cartId, AbandonedCart::firstOrFail()->id);
        $this->assertSame($user->id, AbandonedCart::firstOrFail()->user_id);
        Mail::assertNothingSent();
    }

    private function enableReviews(): void
    {
        $this->automations->configure('reviews', ['enabled' => true, 'status_ids' => [$this->completed->id]]);
    }

    private function permitFakeDelivery(): void
    {
        config(['commerce.local_safe_mode' => false]);
        app(SystemSettingsService::class)->put('store_email_enabled', true);
    }

    private function product(string $code): Product
    {
        $product = Product::create(['code' => $code, 'base_price' => 100, 'stock_qty' => 20, 'is_active' => true, 'payload' => ['opencart' => ['tax_class_id' => 0]]]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);

        return $product;
    }

    private function order(string $code, Product $product, array $attributes = []): Order
    {
        $order = Order::create(array_replace(['order_number' => 'FIXTURE-'.$code, 'source' => 'web', 'status_id' => $this->completed->id,
            'customer_name' => 'Fixture Customer', 'customer_email' => $code.'@example.test', 'placed_at' => '2026-10-02 12:00:00', 'grand_total' => 100], $attributes));
        $order->items()->create(['product_id' => $product->id, 'name' => $product->code, 'code' => $product->code, 'quantity' => 1, 'unit_price' => 100, 'line_total' => 100]);

        return $order;
    }

    private function cart(Product $product, array $attributes = []): AbandonedCart
    {
        return AbandonedCart::create(array_replace(['session_hash' => hash('sha256', random_bytes(20)),
            'email' => 'cart-fixture@example.test', 'email_hash' => WatchlineMessageAutomationService::emailHash('cart-fixture@example.test'),
            'items' => [['product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => 2]],
            'fingerprint' => 'fixture', 'consent_at' => now(), 'last_activity_at' => now()], $attributes));
    }
}
