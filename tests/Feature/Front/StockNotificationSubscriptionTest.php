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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StockNotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        config(['commerce.b2b_only' => false, 'stock_notifications.enabled' => true, 'stock_notifications.emails_enabled' => true]);
        Mail::fake();
    }

    public function test_guest_can_subscribe_once_with_a_normalized_email_and_consent(): void
    {
        $product = $this->product('unavailable');

        $first = $this->postJson(route('stock-notifications.store'), [
            'product_id' => $product->id, 'email' => ' Kupac@Example.com ', 'consent' => '1', 'website' => '',
        ])->assertSuccessful();
        $second = $this->postJson(route('stock-notifications.store'), [
            'product_id' => $product->id, 'email' => 'kupac@example.com', 'consent' => '1',
        ])->assertSuccessful();

        $notification = StockNotification::query()->sole();
        $this->assertSame('kupac@example.com', $notification->email);
        $this->assertEquals($product->id, $notification->product_id);
        $this->assertNull($notification->bundle_id);
        $this->assertNull($notification->user_id);
        $this->assertSame('hr', $notification->locale);
        $this->assertSame('waiting', $notification->status);
        $this->assertNotNull($notification->consented_at);
        $this->assertSame(64, strlen($notification->token));
        $this->assertArrayNotHasKey('token', $notification->toArray());
        $this->assertNotEmpty($first->json('message'));
        $this->assertSame($first->json('message'), $second->json('message'));
        Mail::assertNothingSent();
    }

    public function test_logged_in_subscription_keeps_the_customer_reference(): void
    {
        $user = User::factory()->create();
        $product = $this->product('customer');

        $this->actingAs($user)->postJson(route('stock-notifications.store'), [
            'product_id' => $product->id, 'email' => $user->email, 'consent' => true,
        ])->assertSuccessful();

        $this->assertEquals($user->id, StockNotification::query()->sole()->user_id);
    }

    public function test_a_duplicate_submission_does_not_rearm_a_delivery_completed_during_the_request(): void
    {
        $product = $this->product('concurrent-delivery');
        $notification = StockNotification::create([
            'product_id' => $product->id, 'email' => 'race@example.test', 'locale' => 'hr',
            'status' => 'waiting', 'consented_at' => now(),
        ]);
        $deliveryCompleted = false;
        DB::listen(function ($query) use ($product, $notification, &$deliveryCompleted): void {
            if (! $deliveryCompleted && str_starts_with(strtolower(ltrim($query->sql)), 'select')
                && str_contains($query->sql, 'stock_notifications')) {
                $deliveryCompleted = true;
                Product::whereKey($product->id)->update(['stock_qty' => 5]);
                StockNotification::whereKey($notification->id)->update(['status' => 'sent', 'sent_at' => now()]);
            }
        });

        $this->postJson(route('stock-notifications.store'), [
            'product_id' => $product->id, 'email' => 'race@example.test', 'consent' => true,
        ])->assertSuccessful();

        $this->assertTrue($deliveryCompleted);
        $this->assertSame('sent', $notification->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_sent_and_cancelled_addresses_can_explicitly_subscribe_again_without_duplicate_rows(): void
    {
        $product = $this->product('resubscribe');
        $payload = ['product_id' => $product->id, 'email' => 'kupac@example.com', 'consent' => true];
        $this->postJson(route('stock-notifications.store'), $payload)->assertSuccessful();
        $notification = StockNotification::query()->sole();

        foreach (['sent', 'cancelled'] as $status) {
            $notification->update([
                'status' => $status, 'sent_at' => now()->subDay(), 'attempts' => 2,
                'last_attempt_at' => now()->subDay(), 'last_error' => 'Stara greška', 'consented_at' => now()->subWeek(),
            ]);
            $oldToken = $notification->token;
            $this->postJson(route('stock-notifications.store'), $payload)->assertSuccessful();
            $notification->refresh();

            $this->assertDatabaseCount('stock_notifications', 1);
            $this->assertSame('waiting', $notification->status);
            $this->assertSame(0, $notification->attempts);
            $this->assertNull($notification->sent_at);
            $this->assertNull($notification->last_error);
            $this->assertTrue($notification->consented_at->isToday());
            $this->assertNotSame($oldToken, $notification->token);
        }
        Mail::assertNothingSent();
    }

    public function test_unavailable_product_has_a_complete_html_form_fallback(): void
    {
        $product = $this->product('form-fallback');

        $this->get(route('stock-notifications.create', ['product_id' => $product->id]))
            ->assertOk()
            ->assertSee('Obavijesti me o dostupnosti')
            ->assertSee('Igračka form-fallback')
            ->assertSee('name="email"', escape: false)
            ->assertSee('name="consent"', escape: false)
            ->assertDontSee('data-stock-notify-dialog', escape: false)
            ->assertSee(route('stock-notifications.store'), escape: false);
        $this->assertDatabaseCount('stock_notifications', 0);
    }

    public function test_notification_modal_is_rendered_once_for_real_product_actions_and_is_absent_from_registration(): void
    {
        config(['storefront.brand' => 'libermedia', 'storefront_cache.enabled' => false]);
        $product = $this->product('contextual-modal');

        $html = $this->get(route('products.show', ['slug' => 'notify-contextual-modal']))->assertOk()
            ->assertSee('data-stock-notify-trigger', escape: false)->getContent();
        $this->assertSame(1, substr_count($html, 'id="stock-notification-dialog"'));
        $this->assertStringContainsString(route('stock-notifications.create', ['product_id' => $product->id]), $html);

        $this->get(route('front.auth.register'))->assertOk()
            ->assertDontSee('data-stock-notify-dialog', escape: false)
            ->assertDontSee('name="product_option_value_id"', escape: false);
    }

    public function test_email_and_explicit_consent_are_required(): void
    {
        $product = $this->product('consent');
        $payload = ['product_id' => $product->id, 'email' => 'kupac@example.com'];

        $this->postJson(route('stock-notifications.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson(route('stock-notifications.store'), [...$payload, 'consent' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson(route('stock-notifications.store'), [...$payload, 'email' => 'not-an-email', 'consent' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson(route('stock-notifications.store'), [...$payload, 'email' => ['unexpected' => 'array'], 'consent' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('stock_notifications', 0);
    }

    public function test_honeypot_has_the_same_success_message_without_saving_an_address(): void
    {
        $product = $this->product('honeypot');
        $payload = ['product_id' => $product->id, 'email' => 'kupac@example.com', 'consent' => true];
        $bot = $this->postJson(route('stock-notifications.store'), [...$payload, 'website' => 'https://example.com'])
            ->assertSuccessful();
        $invalidBot = $this->postJson(route('stock-notifications.store'), [
            'product_id' => ['invalid'], 'email' => ['invalid'], 'consent' => [], 'website' => 'spam',
        ])->assertSuccessful();
        $this->assertDatabaseCount('stock_notifications', 0);
        $human = $this->postJson(route('stock-notifications.store'), $payload)->assertSuccessful();

        $this->assertSame($human->json('message'), $bot->json('message'));
        $this->assertSame($human->json('message'), $invalidBot->json('message'));
        $this->assertDatabaseCount('stock_notifications', 1);
    }

    public function test_available_inactive_untranslated_and_unknown_products_cannot_be_subscribed(): void
    {
        $available = $this->product('available', stock: 2);
        $inactive = $this->product('inactive');
        $inactive->update(['is_active' => false]);
        $untranslated = $this->product('untranslated');
        $untranslated->translations()->delete();

        foreach ([$available->id, $inactive->id, $untranslated->id, 999999] as $id) {
            $this->postJson(route('stock-notifications.store'), [
                'product_id' => $id, 'email' => 'kupac@example.com', 'consent' => true,
            ])->assertUnprocessable();
        }

        $this->assertDatabaseCount('stock_notifications', 0);
    }

    public function test_exactly_one_product_or_bundle_target_is_required(): void
    {
        $product = $this->product('target');
        $bundle = $this->bundle($product);
        $payload = ['email' => 'kupac@example.com', 'consent' => true];

        $this->postJson(route('stock-notifications.store'), $payload)->assertUnprocessable();
        $this->postJson(route('stock-notifications.store'), [
            ...$payload, 'product_id' => $product->id, 'bundle_id' => $bundle->id,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('stock_notifications', 0);
    }

    public function test_variant_must_belong_to_the_requested_product_and_be_unavailable(): void
    {
        $product = $this->product('variants');
        $unavailable = $this->variant($product, 'blue');
        $available = $this->variant($product, 'red', stock: 2);
        $other = $this->variant($this->product('other'), 'green');
        $payload = ['product_id' => $product->id, 'email' => 'kupac@example.com', 'consent' => true];

        foreach ([$available->id, $other->id, 999999] as $id) {
            $this->postJson(route('stock-notifications.store'), [...$payload, 'product_option_value_id' => $id])
                ->assertUnprocessable();
        }
        $this->postJson(route('stock-notifications.store'), [...$payload, 'product_option_value_id' => $unavailable->id])
            ->assertSuccessful();

        $this->assertEquals($unavailable->id, StockNotification::query()->sole()->product_option_value_id);
    }

    public function test_unavailable_bundle_can_be_subscribed_but_available_and_expired_bundles_cannot(): void
    {
        $product = $this->product('bundle-item');
        $bundle = $this->bundle($product);
        $payload = ['bundle_id' => $bundle->id, 'email' => 'kupac@example.com', 'consent' => true];

        $this->postJson(route('stock-notifications.store'), $payload)->assertSuccessful();
        $notification = StockNotification::query()->sole();
        $this->assertEquals($bundle->id, $notification->bundle_id);
        $this->assertNull($notification->product_id);

        $product->update(['stock_qty' => 10]);
        $this->postJson(route('stock-notifications.store'), [...$payload, 'email' => 'second@example.com'])
            ->assertUnprocessable();
        $bundle->update(['ends_at' => now()->subDay()]);
        $product->update(['stock_qty' => 0]);
        $this->postJson(route('stock-notifications.store'), [...$payload, 'email' => 'third@example.com'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('stock_notifications', 1);
    }

    public function test_signed_in_bundle_uses_the_customers_own_group_to_check_availability(): void
    {
        $user = User::factory()->create();
        $product = $this->product('private-bundle-item', stock: 10);
        $bundle = $this->bundle($product);
        $privateGroup = CustomerGroup::query()->create([
            'code' => 'notify-private', 'name' => 'Privatni kupci', 'is_active' => true, 'is_default' => false,
        ]);
        $user->customerGroups()->attach($privateGroup->id);
        $bundle->discounts()->update(['customer_group_id' => $privateGroup->id]);
        $payload = ['bundle_id' => $bundle->id, 'email' => $user->email, 'consent' => true];

        $this->actingAs($user)->postJson(route('stock-notifications.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('bundle_id');
        $this->assertDatabaseCount('stock_notifications', 0);

        $product->update(['stock_qty' => 0]);
        $this->postJson(route('stock-notifications.store'), $payload)->assertSuccessful();

        $notification = StockNotification::query()->sole();
        $this->assertEquals($user->id, $notification->user_id);
        $this->assertEquals($bundle->id, $notification->bundle_id);
        $this->assertSame('waiting', $notification->status);
    }

    public function test_unsubscribe_link_requires_confirmation_before_cancelling(): void
    {
        $product = $this->product('unsubscribe');
        $this->postJson(route('stock-notifications.store'), [
            'product_id' => $product->id, 'email' => 'kupac@example.com', 'consent' => true,
        ])->assertSuccessful();
        $notification = StockNotification::query()->sole();

        $this->get(route('stock-notifications.unsubscribe', ['token' => $notification->token]))->assertOk();
        $this->assertSame('waiting', $notification->fresh()->status, 'Email link previews must not cancel a subscription.');
        $this->post(route('stock-notifications.cancel', ['token' => $notification->token]))->assertStatus(200);
        $this->assertSame('cancelled', $notification->fresh()->status);
        $this->get(route('stock-notifications.unsubscribe', ['token' => str_repeat('x', 64)]))->assertNotFound();
    }

    private function product(string $code, int $stock = 0): Product
    {
        $product = Product::query()->create([
            'code' => 'notify-'.$code, 'sku' => 'notify-'.$code, 'base_price' => 20,
            'stock_qty' => $stock, 'supplier_stock_qty' => 0, 'is_active' => true,
            'minimum_order_quantity' => 1, 'order_quantity_step' => 1,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Igračka '.$code, 'slug' => 'notify-'.$code]);

        return $product;
    }

    private function variant(Product $product, string $code, int $stock = 0): ProductOptionValue
    {
        $option = Option::query()->create(['code' => 'notify-'.$code, 'type' => 'select', 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => $code, 'is_active' => true]);
        $product->options()->attach($option->id);

        return $product->optionValues()->create([
            'option_value_id' => $value->id, 'mode' => 'single', 'sku' => $product->sku.'-'.$code,
            'stock_qty' => $stock, 'is_active' => true, 'combination_hash' => hash('sha256', 's:'.$value->id),
        ]);
    }

    private function bundle(Product $unavailable): Bundle
    {
        $other = $this->product('bundle-other', stock: 10);
        $group = CustomerGroup::query()->create([
            'code' => 'notify-public', 'name' => 'Kupci', 'is_active' => true, 'is_default' => true,
        ]);
        $bundle = Bundle::query()->create(['title' => 'Poklon komplet', 'is_active' => true]);
        $bundle->products()->attach([$unavailable->id => ['quantity' => 1], $other->id => ['quantity' => 1]]);
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 10]);

        return $bundle;
    }
}
