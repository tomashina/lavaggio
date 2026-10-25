<?php

namespace Tests\Feature\Front;

use App\Livewire\Admin\Settings\System\StoreSettings;
use App\Mail\NewsletterCouponMail;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Models\User\NewsletterSignup;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Front\NewsletterSignupService;
use App\Services\Front\WatchlineNewsletterWelcomeService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineNewsletterWelcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'commerce.local_safe_mode' => true]);
        app()->setLocale('hr');
        Mail::fake();
        app(SystemSettingsService::class)->putMany([
            'store_newsletter_provider' => 'database', 'store_newsletter_enabled' => true,
            'store_newsletter_coupon_enabled' => true, 'store_newsletter_coupon_percent' => 10,
            'store_email_enabled' => false, 'store_cart_coupon_enabled' => true,
        ]);
    }

    public function test_new_consent_issues_one_private_no_expiry_code_and_repeated_signup_never_resends(): void
    {
        $this->postJson('/newsletter/subscribe', $this->signupPayload('NewBuyer@example.test'))->assertOk()
            ->assertJsonPath('message', __('ui.front.desktop.newsletter.status.welcome_pending'));
        $signup = NewsletterSignup::sole();
        $action = CatalogAction::sole();
        $this->assertSame('newbuyer@example.test', $signup->email);
        $this->assertSame(1, $action->usage_limit);
        $this->assertSame(0, $action->used_count);
        $this->assertSame('10.00', $action->discount_value);
        $this->assertNull($action->ends_at);
        $this->assertSame('pending', data_get($signup->payload, 'watchline_welcome.delivery_status'));
        $this->assertNotSame('WELCOME10', $action->coupon_code);
        $this->assertNotSame('BALI10', $action->coupon_code);
        $this->permitFakeMail();
        $response = $this->postJson('/newsletter/subscribe', $this->signupPayload('NEWBUYER@example.test'))->assertOk()
            ->assertJsonPath('message', __('ui.front.desktop.newsletter.status.already_subscribed'));
        $this->assertStringNotContainsString($action->coupon_code, $response->getContent());
        $this->assertDatabaseCount('catalog_actions', 1);
        Mail::assertNothingSent();
    }

    public function test_validation_and_imported_pending_consent_do_not_issue_a_code(): void
    {
        $this->postJson('/newsletter/subscribe', ['newsletter_email' => 'not-an-email'])->assertUnprocessable()
            ->assertJsonValidationErrors(['newsletter_email', 'newsletter_accept_terms']);
        NewsletterSignup::create(['email' => 'Existing@example.test', 'source' => 'opencart', 'provider' => 'database',
            'consent_accepted' => true, 'sync_status' => 'pending', 'payload' => ['legacy' => 'preserved']]);
        $this->postJson('/newsletter/subscribe', $this->signupPayload('existing@example.test'))->assertOk();
        $this->assertDatabaseCount('catalog_actions', 0);
        $this->assertDatabaseCount('newsletter_signups', 1);
        $this->assertSame('preserved', data_get(NewsletterSignup::sole()->payload, 'legacy'));
        Mail::assertNothingSent();
    }

    public function test_registered_first_buyer_is_eligible_but_paid_or_completed_guest_and_account_history_is_not(): void
    {
        $new = User::factory()->create(['email' => 'registered@example.test']);
        $this->assertSame('issued', $this->subscribe($new->email, $new)['welcome']);
        $completed = OrderStatus::create(['code' => 'herrera-oc-status-5', 'name' => 'Dovršeno', 'is_paid' => true, 'is_active' => true]);
        $this->history('guest-paid', 'Prior@example.test', ['paid_at' => now()]);
        $this->assertSame('ineligible', $this->subscribe('PRIOR@example.test')['welcome']);
        $account = User::factory()->create(['email' => 'current@example.test']);
        $this->history('old-email', 'former@example.test', ['user_id' => $account->id, 'status_id' => $completed->id]);
        $this->assertSame('ineligible', $this->subscribe($account->email, $account)['welcome']);
        $this->assertSame('existing', $this->subscribe('CURRENT@example.test')['welcome']);
        // A fresh guest signup still checks completed purchases linked to the matching customer account.
        $guestAccount = User::factory()->create(['email' => 'guest-account@example.test']);
        $this->history('guest-old-email', 'previous-address@example.test', ['user_id' => $guestAccount->id, 'status_id' => $completed->id]);
        $this->assertSame('ineligible', $this->subscribe('GUEST-ACCOUNT@example.test')['welcome']);
        $this->assertDatabaseCount('catalog_actions', 1);
    }

    public function test_local_environment_safe_mode_and_global_email_flag_independently_block_delivery(): void
    {
        $signup = $this->subscribe('guard@example.test')['signup'];
        $service = app(WatchlineNewsletterWelcomeService::class);
        $this->assertSame('blocked', $service->deliver($signup));
        config(['commerce.local_safe_mode' => false]);
        $this->assertSame('blocked', $service->deliver($signup));
        $this->permitFakeMail();
        $this->app['env'] = 'local';
        $this->assertSame('blocked', $service->deliver($signup));
        $this->assertNull(data_get($signup->fresh()->payload, 'watchline_welcome.delivered_at'));
        $this->assertSame(0, CatalogAction::sole()->used_count);
        Mail::assertNothingSent();
    }

    public function test_delivery_uses_watchline_mail_percent_and_is_idempotent(): void
    {
        app(SystemSettingsService::class)->put('store_newsletter_coupon_percent', 12.5);
        $signup = $this->subscribe('mail@example.test')['signup'];
        $this->permitFakeMail();
        $service = app(WatchlineNewsletterWelcomeService::class);
        $this->assertSame('sent', $service->deliver($signup));
        $this->assertSame('not_pending', $service->deliver($signup));
        Mail::assertSent(NewsletterCouponMail::class, function (NewsletterCouponMail $mail): bool {
            $render = $mail->render();

            return $mail->hasTo('mail@example.test') && $mail->watchline && $mail->discountPercent === 12.5
                && str_contains($render, 'watchline-mail-logo.png') && str_contains($render, '12.5%')
                && ! str_contains($render, 'BALI') && ! str_contains($render, 'LIBERMEDIA');
        });
        Mail::assertSentCount(1);
        $this->assertNotNull(data_get($signup->fresh()->payload, 'watchline_welcome.delivered_at'));
        $this->assertSame(0, CatalogAction::sole()->used_count);
    }

    public function test_failed_delivery_does_not_consume_coupon_and_manual_retry_reuses_the_same_code(): void
    {
        $signup = $this->subscribe('retry@example.test')['signup'];
        $this->permitFakeMail();
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Fixture transport failure'));
        $service = app(WatchlineNewsletterWelcomeService::class);
        $this->assertSame('failed', $service->deliver($signup));
        $this->assertNull(data_get($signup->fresh()->payload, 'watchline_welcome.delivered_at'));
        $this->assertSame(0, CatalogAction::sole()->used_count);
        $this->assertSame('not_pending', $service->deliver($signup));
        Mail::swap($mailFake);
        $this->assertSame('sent', $service->retryDelivery($signup));
        $this->assertDatabaseCount('catalog_actions', 1);
        Mail::assertSentCount(1);
    }

    public function test_sale_and_welcome_stack_and_checkout_reserves_one_use_then_cancelled_unpaid_releases_it(): void
    {
        $this->checkoutMethods();
        $signup = $this->subscribe('first@example.test')['signup'];
        $action = CatalogAction::sole();
        $product = $this->product();
        CatalogAction::create(['code' => 'sale15', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 15,
            'target_type' => 'all', 'audience_type' => 'all', 'is_active' => true]);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product));
        $this->assertTrue($cart->applyCoupon($action->coupon_code));
        $this->assertSame(85.0, (float) $cart->lines()->sole()['line_total']);
        $this->assertSame(8.5, $cart->summary()['cart_discount_total']);
        $this->assertSame(76.5, $cart->summary()['grand_total']);
        $order = app(CheckoutService::class)->placeOrder($this->checkoutPayload($signup->email));
        $this->assertSame(76.5, (float) $order->grand_total);
        $this->assertSame($action->id, data_get($order->payload, 'watchline_newsletter_welcome.action_id'));
        $this->assertSame(1, $action->fresh()->used_count);
        $this->assertFalse(app(WatchlineNewsletterWelcomeService::class)->canApply($action->fresh()));
        $cancelled = OrderStatus::create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => true, 'is_active' => true]);
        $order->update(['status_id' => $cancelled->id]);
        $this->assertSame(0, $action->fresh()->used_count);
        $this->assertTrue(app(WatchlineNewsletterWelcomeService::class)->canApply($action->fresh()));
        $order->update(['paid_at' => now()]);
        $this->assertFalse(app(WatchlineNewsletterWelcomeService::class)->canApply($action->fresh()));
    }

    public function test_failed_order_transaction_rolls_back_coupon_reservation_and_second_use_is_rejected(): void
    {
        $this->checkoutMethods();
        $signup = $this->subscribe('rollback@example.test')['signup'];
        $action = CatalogAction::sole();
        $cart = app(CartService::class);
        $cart->add($this->product());
        $this->assertTrue($cart->applyCoupon($action->coupon_code));
        $summary = $cart->summary();
        try {
            DB::transaction(function () use ($action, $signup, $summary): void {
                app(WatchlineNewsletterWelcomeService::class)->reserveForCheckout($action->coupon_code, $signup->email, null, $summary);
                throw new RuntimeException('Fixture order write failed');
            });
        } catch (RuntimeException) {
            $this->assertSame(0, $action->fresh()->used_count);
        }
        app(CheckoutService::class)->placeOrder($this->checkoutPayload($signup->email));
        try {
            app(CheckoutService::class)->placeOrder($this->checkoutPayload($signup->email));
            $this->fail('A code already reserved by an order must not be reused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, $action->fresh()->used_count);
    }

    public function test_recipient_tampering_is_rejected_server_side_without_order_or_stock_writes(): void
    {
        $this->checkoutMethods();
        $this->subscribe('owner@example.test');
        $action = CatalogAction::sole();
        $product = $this->product();
        $cart = app(CartService::class);
        $cart->add($product);
        $this->assertTrue($cart->applyCoupon($action->coupon_code));
        try {
            app(CheckoutService::class)->placeOrder($this->checkoutPayload('different@example.test'));
            $this->fail('A personal code must require its recipient email.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coupon_code', $exception->errors());
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(20, $product->fresh()->stock_qty);
        $this->assertSame(0, $action->fresh()->used_count);
    }

    public function test_post_signup_paid_order_and_linked_account_reservation_cannot_be_bypassed_as_guest(): void
    {
        $user = User::factory()->create(['email' => 'old@example.test']);
        $signup = $this->subscribe($user->email, $user)['signup'];
        $action = CatalogAction::sole();
        $user->update(['email' => 'new@example.test']);
        $this->history('reserved-alias', $user->email, ['user_id' => $user->id,
            'payload' => ['watchline_newsletter_welcome' => ['action_id' => 999]]]);
        $this->assertFalse(app(WatchlineNewsletterWelcomeService::class)->canApply($action));
        $this->assertSame('ineligible', $this->subscribe($user->email, $user)['welcome']);
        $this->assertDatabaseCount('catalog_actions', 1);
        $this->history('paid-after-signup', $signup->email, ['paid_at' => now()]);
        $this->assertFalse(app(WatchlineNewsletterWelcomeService::class)->canApply($action));
    }

    public function test_known_account_email_aliases_cannot_redeem_separate_codes_through_guest_orders(): void
    {
        $user = User::factory()->create(['email' => 'alias-old@example.test']);
        $first = $this->subscribe($user->email, $user)['signup'];
        $firstAction = CatalogAction::sole();
        $user->update(['email' => 'alias-new@example.test']);
        $second = $this->subscribe($user->email, $user)['signup'];
        $secondAction = CatalogAction::where('id', '!=', $firstAction->id)->sole();
        $this->history('guest-alias-order', $first->email, ['user_id' => null, 'payload' => [
            'watchline_newsletter_welcome' => ['action_id' => $firstAction->id, 'signup_id' => $first->id],
        ]]);
        $this->assertSame($first->user_id, $second->user_id);
        $this->assertFalse(app(WatchlineNewsletterWelcomeService::class)->canApply($secondAction));
    }

    public function test_local_provider_sync_is_pending_without_external_api_calls(): void
    {
        Http::fake();
        app(SystemSettingsService::class)->putMany(['store_newsletter_provider' => 'mailchimp',
            'store_newsletter_mailchimp_api_key' => 'fixture-us1', 'store_newsletter_mailchimp_list_id' => 'fixture-list']);
        $this->permitFakeMail();
        $this->app['env'] = 'local';
        $result = $this->subscribe('external@example.test');
        $this->assertFalse($result['synced']);
        $this->assertSame('pending', $result['signup']->sync_status);
        $this->assertSame('issued', $result['welcome']);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_disabled_welcome_never_falls_back_to_the_other_brand_coupon(): void
    {
        $this->permitFakeMail();
        app(SystemSettingsService::class)->put('store_newsletter_coupon_enabled', false);
        $this->postJson('/newsletter/subscribe', $this->signupPayload('disabled@example.test'))->assertOk();
        $this->assertDatabaseCount('newsletter_signups', 1);
        $this->assertDatabaseCount('catalog_actions', 0);
        Mail::assertNothingSent();
    }

    public function test_admin_can_edit_coupon_percentage_and_disable_it_without_changing_email_guard(): void
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);
        Livewire::actingAs($admin)->test(StoreSettings::class)->set('tab', 'newsletter')
            ->assertSee('Popust za prvu kupnju')->set('form.store_newsletter_coupon_percent', 15)
            ->set('form.store_newsletter_coupon_enabled', false)->call('save')->assertHasNoErrors();
        $settings = app(SystemSettingsService::class);
        $this->assertEquals(15, $settings->get('store_newsletter_coupon_percent'));
        $this->assertFalse($settings->get('store_newsletter_coupon_enabled'));
        $this->assertFalse($settings->get('store_email_enabled'));
        Livewire::actingAs($admin)->test(StoreSettings::class)->set('tab', 'newsletter')
            ->set('form.store_newsletter_coupon_percent', 101)->call('save')->assertHasErrors(['form.store_newsletter_coupon_percent']);
    }

    private function subscribe(string $email, ?User $user = null): array
    {
        return app(NewsletterSignupService::class)->subscribe($email, true, $user, locale: 'hr');
    }

    private function signupPayload(string $email): array
    {
        return ['newsletter_email' => $email, 'newsletter_accept_terms' => true];
    }

    private function permitFakeMail(): void
    {
        config(['commerce.local_safe_mode' => false]);
        app(SystemSettingsService::class)->put('store_email_enabled', true);
    }

    private function history(string $code, string $email, array $extra = []): Order
    {
        return Order::create($extra + ['order_number' => $code, 'customer_name' => 'Fixture Customer', 'customer_email' => $email, 'currency_code' => 'EUR', 'grand_total' => 100]);
    }

    private function product(): Product
    {
        $product = Product::create(['code' => 'welcome-watch', 'sku' => 'WELCOME-WATCH', 'base_price' => 100, 'stock_qty' => 20, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Fixture Watch', 'slug' => 'fixture-watch']);

        return $product;
    }

    private function checkoutMethods(): void
    {
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::create(['code' => 'new', 'name' => 'Novo', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
    }

    private function checkoutPayload(string $email): array
    {
        return ['customer_email' => $email, 'billing_country_code' => 'HR', 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb',
            'billing_address_line_1' => 'Fixture1', 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank'];
    }
}
