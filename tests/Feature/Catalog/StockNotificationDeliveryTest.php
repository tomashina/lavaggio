<?php

namespace Tests\Feature\Catalog;

use App\Mail\BackInStockMail;
use App\Models\Catalog\Bundle\Bundle;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Catalog\StockNotification;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\StockNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockNotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        config([
            'commerce.b2b_only' => false, 'stock_notifications.enabled' => true, 'stock_notifications.emails_enabled' => true,
            'stock_notifications.retry_minutes' => 5, 'stock_notifications.max_attempts' => 3,
        ]);
        Mail::fake();
    }

    public function test_unavailable_inactive_and_untranslated_products_never_send(): void
    {
        $empty = $this->product('empty');
        $inactive = $this->product('inactive', stock: 10);
        $inactive->update(['is_active' => false]);
        $untranslated = $this->product('untranslated', stock: 10);
        $untranslated->translations()->delete();

        foreach ([$empty, $inactive, $untranslated] as $product) {
            $notification = $this->notification($product);
            $this->assertFalse($this->service()->sendNow($notification->id)['sent']);
            $this->assertSame('waiting', $notification->fresh()->status);
            $this->assertNull($notification->fresh()->sent_at);
        }

        Mail::assertNothingSent();
    }

    public function test_new_supplier_stock_sends_exactly_once_and_records_delivery(): void
    {
        $product = $this->product('supplier');
        $notification = $this->notification($product);
        $product->update(['supplier_stock_qty' => 6]);

        $this->assertTrue($this->service()->sendNow($notification->id)['sent']);
        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->sent_at);
        $this->assertNotNull($notification->last_attempt_at);
        $this->assertNull($notification->last_error);
        $this->assertFalse($this->service()->sendNow($notification->id)['sent']);
        Mail::assertSent(BackInStockMail::class, fn (BackInStockMail $mail) => $mail->hasTo($notification->email));
        Mail::assertSentCount(1);
        $this->assertSame(0, $product->fresh()->stock_qty);
        $this->assertSame(6, $product->fresh()->supplier_stock_qty);
    }

    public function test_order_step_is_relative_to_the_minimum_for_a_generic_product(): void
    {
        $product = $this->product('relative-step', stock: 1);
        $product->update(['minimum_order_quantity' => 1, 'order_quantity_step' => 2]);
        $notification = $this->notification($product);

        $this->assertTrue($this->service()->isAvailable($notification));
        $this->assertTrue($this->service()->sendNow($notification->id)['sent']);
        $this->assertSame('sent', $notification->fresh()->status);
        Mail::assertSentCount(1);
    }

    public function test_email_renders_the_product_link_and_a_confirmation_unsubscribe_link_in_croatian(): void
    {
        $product = $this->product('mail-preview', stock: 5);
        $notification = $this->notification($product);

        $html = (new BackInStockMail($notification))->render();

        $this->assertStringContainsString('Igračka mail-preview', $html);
        $this->assertStringContainsString(route('products.show', ['slug' => 'delivery-mail-preview']), $html);
        $this->assertStringContainsString(route('stock-notifications.unsubscribe', ['token' => $notification->token]), $html);
        $this->assertStringContainsString('Odjava', $html);
    }

    public function test_mail_failure_is_saved_and_a_manual_retry_can_succeed(): void
    {
        $notification = $this->notification($this->product('retry', stock: 5));
        config(['mail.default' => 'smtp']);
        $mailFake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->with($notification->email)->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('SMTP temporarily unavailable'));

        $this->assertFalse($this->service()->sendNow($notification->id)['sent']);
        $notification->refresh();
        $this->assertSame('failed', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertStringContainsString('RuntimeException', $notification->last_error);
        $this->assertNull($notification->sent_at);
        $this->assertNotNull($notification->last_attempt_at);

        Mail::swap($mailFake);
        $this->assertTrue($this->service()->sendNow($notification->id)['sent']);
        $this->assertSame(2, $notification->fresh()->attempts);
        $this->assertNull($notification->fresh()->last_error);
        Mail::assertSentCount(1);
    }

    public function test_confirmed_email_is_not_retried_when_recording_its_delivery_fails(): void
    {
        $notification = $this->notification($this->product('delivery-record', stock: 5));
        $interrupted = false;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$interrupted): void {
            if (! $interrupted && str_starts_with(strtolower(ltrim($sql)), 'update')
                && str_contains($sql, 'stock_notifications') && in_array('sent', $bindings, true)) {
                $interrupted = true;
                throw new \RuntimeException('Temporary database failure after mail acceptance');
            }
        });

        $this->assertSame('unconfirmed', $this->service()->sendNow($notification->id)['reason']);
        $this->assertTrue($interrupted);
        $this->assertSame('processing', $notification->fresh()->status);
        $this->assertSame('processed', $this->service()->sendNow($notification->id)['reason']);
        $this->assertSame(0, $this->service()->processReady()['ready']);
        Mail::assertSentCount(1);
    }

    public function test_generic_product_uses_any_visible_variant_but_specific_variant_waits_for_its_own_stock(): void
    {
        $product = $this->product('variants', stock: 20);
        $first = $this->variant($product, 'blue');
        $second = $this->variant($product, 'red');
        $generic = $this->notification($product);
        $specific = $this->notification($product, ['product_option_value_id' => $first->id]);

        $this->assertFalse($this->service()->isAvailable($generic));
        $second->update(['stock_qty' => 4]);
        $this->assertTrue($this->service()->isAvailable($generic->fresh()));
        $this->assertFalse($this->service()->isAvailable($specific->fresh()));
        $this->assertTrue($this->service()->sendNow($generic->id)['sent']);
        $this->assertFalse($this->service()->sendNow($specific->id)['sent']);

        $first->update(['stock_qty' => 2]);
        $this->assertTrue($this->service()->sendNow($specific->id)['sent']);
        Mail::assertSentCount(2);
    }

    public function test_hidden_or_inactive_variants_do_not_trigger_a_generic_notification(): void
    {
        $product = $this->product('hidden');
        $variant = $this->variant($product, 'hidden', stock: 5);
        $variant->optionValue->option->update(['payload' => [Option::PAYLOAD_SHOW_ON_PRODUCT_PAGE => false]]);
        $notification = $this->notification($product);

        $this->assertFalse($this->service()->isAvailable($notification));
        $variant->optionValue->option->update(['payload' => []]);
        $variant->update(['is_active' => false]);
        $this->assertFalse($this->service()->isAvailable($notification->fresh()));
        Mail::assertNothingSent();
    }

    public function test_bundle_waits_until_every_required_quantity_can_be_purchased(): void
    {
        $first = $this->product('bundle-first', stock: 1);
        $second = $this->product('bundle-second');
        $bundle = Bundle::query()->create(['title' => 'Komplet za poklon', 'is_active' => true]);
        $bundle->products()->attach([$first->id => ['quantity' => 2], $second->id => ['quantity' => 1]]);
        $this->addPublicDiscount($bundle);
        $notification = StockNotification::query()->create([
            'bundle_id' => $bundle->id, 'target_key' => 'bundle:'.$bundle->id,
            'email' => 'bundle@example.com', 'locale' => 'hr', 'status' => 'waiting',
            'consented_at' => now(), 'token' => Str::random(64),
        ]);

        $this->assertFalse($this->service()->sendNow($notification->id)['sent']);
        $second->update(['stock_qty' => 5]);
        $this->assertFalse($this->service()->sendNow($notification->id)['sent']);
        $first->update(['supplier_stock_qty' => 1]);
        $this->assertTrue($this->service()->sendNow($notification->id)['sent']);
        Mail::assertSentCount(1);
    }

    public function test_expired_bundle_does_not_send_even_if_all_products_have_stock(): void
    {
        $bundle = Bundle::query()->create(['title' => 'Stari komplet', 'is_active' => true, 'ends_at' => now()->subDay()]);
        $first = $this->product('expired-first', stock: 5);
        $second = $this->product('expired-second', stock: 5);
        $bundle->products()->attach([$first->id => ['quantity' => 1], $second->id => ['quantity' => 1]]);
        $this->addPublicDiscount($bundle);
        $notification = StockNotification::query()->create([
            'bundle_id' => $bundle->id, 'target_key' => 'bundle:'.$bundle->id,
            'email' => 'expired@example.com', 'locale' => 'hr', 'status' => 'waiting',
            'consented_at' => now(), 'token' => Str::random(64),
        ]);

        $this->assertFalse($this->service()->sendNow($notification->id)['sent']);
        Mail::assertNothingSent();
    }

    public function test_guest_bundle_notification_waits_for_an_active_public_discount(): void
    {
        $first = $this->product('audience-first', stock: 5);
        $second = $this->product('audience-second', stock: 5);
        $bundle = Bundle::query()->create(['title' => 'Komplet za kupce', 'is_active' => true]);
        $bundle->products()->attach([$first->id => ['quantity' => 1], $second->id => ['quantity' => 1]]);
        $this->addPublicDiscount($bundle);
        $publicGroup = CustomerGroup::query()->where('code', 'delivery-public')->sole();
        $privateGroup = CustomerGroup::query()->create([
            'code' => 'delivery-private', 'name' => 'Posebna grupa', 'is_active' => true, 'is_default' => false,
        ]);
        $notification = StockNotification::query()->create([
            'bundle_id' => $bundle->id, 'target_key' => 'bundle:'.$bundle->id,
            'email' => 'public@example.com', 'locale' => 'hr', 'status' => 'waiting',
            'consented_at' => now(), 'token' => Str::random(64),
        ]);
        $bundle->discounts()->update(['customer_group_id' => $privateGroup->id]);

        $this->assertFalse($this->service()->sendNow($notification->id)['sent']);
        $this->assertSame('waiting', $notification->fresh()->status);
        Mail::assertNothingSent();

        $bundle->discounts()->update(['customer_group_id' => $publicGroup->id]);
        $publicGroup->update(['is_active' => false]);
        $this->assertFalse($this->service()->sendNow($notification->id)['sent']);
        Mail::assertNothingSent();

        $publicGroup->update(['is_active' => true]);
        $this->assertTrue($this->service()->sendNow($notification->id)['sent']);
        Mail::assertSentCount(1);
    }

    public function test_cancelled_subscription_and_disabled_delivery_do_not_send(): void
    {
        $product = $this->product('cancelled', stock: 5);
        $cancelled = $this->notification($product, ['status' => 'cancelled']);
        $waiting = $this->notification($product, ['email' => 'another@example.com']);

        $this->assertFalse($this->service()->sendNow($cancelled->id)['sent']);
        config(['stock_notifications.emails_enabled' => false]);
        $this->assertFalse($this->service()->sendNow($waiting->id)['sent']);
        $this->assertSame('waiting', $waiting->fresh()->status);
        $this->assertSame(0, $waiting->fresh()->attempts);
        Mail::assertNothingSent();
    }

    public function test_renamed_log_transport_and_failover_to_log_never_claim_a_delivery(): void
    {
        $notification = $this->notification($this->product('non-delivery-mailer', stock: 5));
        $before = $notification->fresh()->getRawOriginal();
        $mailManager = \Mockery::mock(\Illuminate\Mail\MailManager::class);
        $mailManager->shouldNotReceive('to');
        $mailManager->shouldNotReceive('send');
        Mail::swap($mailManager);
        config([
            'stock_notifications.enabled' => true,
            'stock_notifications.emails_enabled' => true,
            'mail.mailers.preview' => ['transport' => 'log'],
            'mail.mailers.delivery-with-log-fallback' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
        ]);

        foreach (['preview', 'delivery-with-log-fallback'] as $mailer) {
            config(['mail.default' => $mailer]);

            $this->assertFalse($this->service()->canSend(), $mailer.' cannot guarantee email delivery.');
            $result = $this->service()->sendNow($notification->id);
            $this->assertFalse($result['sent']);
            $this->assertSame('disabled', $result['reason']);
            $this->assertSame($before, $notification->fresh()->getRawOriginal());
        }
    }

    public function test_dry_run_reports_ready_notifications_without_sending_or_changing_rows(): void
    {
        $ready = $this->notification($this->product('dry-ready', stock: 5));
        $empty = $this->notification($this->product('dry-empty'));
        $before = [$ready->id => $ready->fresh()->getRawOriginal(), $empty->id => $empty->fresh()->getRawOriginal()];

        $result = $this->service()->processReady(limit: 50, dryRun: true);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['ready']);
        $this->assertSame(0, $result['sent']);
        $this->assertSame($before[$ready->id], $ready->fresh()->getRawOriginal());
        $this->assertSame($before[$empty->id], $empty->fresh()->getRawOriginal());
        Mail::assertNothingSent();
    }

    public function test_automatic_delivery_respects_retry_delay_and_maximum_attempts(): void
    {
        $product = $this->product('backoff', stock: 5);
        $recent = $this->notification($product, ['status' => 'failed', 'attempts' => 1, 'last_attempt_at' => now()]);
        $exhausted = $this->notification($product, [
            'email' => 'exhausted@example.com', 'status' => 'failed', 'attempts' => 3, 'last_attempt_at' => now()->subHour(),
        ]);

        $this->service()->processReady();
        Mail::assertNothingSent();
        $this->assertSame('failed', $exhausted->fresh()->status);
        $this->travel(6)->minutes();
        $this->service()->processReady();

        Mail::assertSentCount(1);
        $this->assertSame('sent', $recent->fresh()->status);
        $this->assertSame('failed', $exhausted->fresh()->status);
    }

    private function service(): StockNotificationService
    {
        return app(StockNotificationService::class);
    }

    private function addPublicDiscount(Bundle $bundle): void
    {
        $group = CustomerGroup::query()->create([
            'code' => 'delivery-public', 'name' => 'Kupci', 'is_active' => true, 'is_default' => true,
        ]);
        $bundle->discounts()->create(['customer_group_id' => $group->id, 'type' => 'percent', 'value' => 10]);
    }

    private function notification(Product $product, array $overrides = []): StockNotification
    {
        $variantId = $overrides['product_option_value_id'] ?? null;

        return StockNotification::query()->create([
            'product_id' => $product->id, 'target_key' => 'product:'.$product->id.($variantId ? ':option:'.$variantId : ''),
            'email' => 'kupac@example.com', 'locale' => 'hr', 'status' => 'waiting',
            'consented_at' => now(), 'token' => Str::random(64), ...$overrides,
        ]);
    }

    private function product(string $code, int $stock = 0): Product
    {
        $product = Product::query()->create([
            'code' => 'delivery-'.$code, 'sku' => 'delivery-'.$code, 'base_price' => 20,
            'stock_qty' => $stock, 'supplier_stock_qty' => 0, 'is_active' => true,
            'minimum_order_quantity' => 1, 'order_quantity_step' => 1,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Igračka '.$code, 'slug' => 'delivery-'.$code]);

        return $product;
    }

    private function variant(Product $product, string $code, int $stock = 0): ProductOptionValue
    {
        $option = Option::query()->create(['code' => 'delivery-'.$code, 'type' => 'select', 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => $code, 'is_active' => true]);
        $product->options()->attach($option->id);

        return $product->optionValues()->create([
            'option_value_id' => $value->id, 'mode' => 'single', 'sku' => $product->sku.'-'.$code,
            'stock_qty' => $stock, 'is_active' => true, 'combination_hash' => hash('sha256', 's:'.$value->id),
        ]);
    }
}
