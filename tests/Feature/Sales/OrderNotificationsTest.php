<?php

namespace Tests\Feature\Sales;

use App\Livewire\Admin\Sales\Order\Show;
use App\Mail\OrderUpdateMail;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderNotification;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Services\Sales\OrderNotificationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class OrderNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Order $order;

    private OrderStatus $paid;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'mail.default' => 'array']);
        app()->setLocale('hr');
        Mail::fake();
        app(SystemSettingsService::class)->put('store_email_enabled', true);
        $this->admin = User::factory()->create();
        Bouncer::assign('admin')->to($this->admin);
        $new = OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_active' => true]);
        $this->paid = OrderStatus::query()->create(['code' => 'paid', 'name' => 'Paid', 'is_active' => true, 'is_paid' => true]);
        $this->order = Order::query()->create([
            'order_number' => 'MAIL-001', 'status_id' => $new->id, 'customer_name' => 'Test Kupac',
            'customer_email' => 'kupac@example.test', 'currency_code' => 'EUR', 'grand_total' => 45,
        ]);
    }

    public function test_status_changes_send_once_and_private_notes_are_not_emailed(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $this->order->id])
            ->set('form.status_id', $this->paid->id)->set('form.comment', 'INTERNAL-SECRET')
            ->call('updateStatus')->assertHasNoErrors()
            ->assertSee('Povijest e-mail obavijesti')->assertSee('kupac@example.test')->assertSee('Poslano');
        $component->call('updateStatus')->set('form.comment', 'Another private note')->call('updateStatus');
        Mail::assertSentCount(1);
        Mail::assertSent(OrderUpdateMail::class, function ($mail): bool {
            $html = $mail->render();
            $this->assertStringContainsString('Plaćeno', $html);
            $this->assertStringContainsString('lavaggio-mail-logo.png', $html);
            $this->assertStringNotContainsString('INTERNAL-SECRET', $html);
            $this->assertStringNotContainsString('Another private note', $html);
            $this->assertStringNotContainsString('Pregledaj narudžbu u svom računu', $html);

            return $mail->hasTo('kupac@example.test');
        });
        $notification = $this->order->notifications()->sole();
        $this->assertSame('sent', $notification->status);
        $this->assertNotNull($notification->sent_at);
        $this->assertNotNull($notification->order_history_id);
        $this->assertCount(1, $notification->attempts);
    }

    public function test_quick_actions_and_retrying_sent_mail_do_not_send_duplicates(): void
    {
        $component = Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $this->order->id])
            ->call('quickStatusByCode', 'paid')->call('quickStatusByCode', 'paid');
        $notification = $this->order->notifications()->sole();
        $component->call('retryNotification', $notification->id);
        app(OrderNotificationService::class)->statusChanged($this->order->history()->first());
        Mail::assertSentCount(1);
        $this->assertCount(1, $notification->fresh()->attempts);
    }

    public function test_failure_does_not_undo_status_and_retry_keeps_both_attempts(): void
    {
        $fake = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP secret-password'));
        $component = Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $this->order->id])
            ->call('quickStatusByCode', 'paid')->assertHasNoErrors()->assertSee('Slanje nije uspjelo');
        $notification = $this->order->notifications()->sole();
        $this->assertSame($this->paid->id, $this->order->fresh()->status_id);
        $this->assertSame('failed', $notification->status);
        $this->assertNull($notification->sent_at);
        $this->assertStringNotContainsString('secret-password', json_encode($notification->toArray()));

        Mail::swap($fake);
        $component->call('retryNotification', $notification->id)->assertHasNoErrors();
        Mail::assertSentCount(1);
        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertSame(['failed', 'sent'], array_column($notification->attempts, 'status'));
        $this->assertSame($this->admin->id, $notification->attempts[1]['by']);
    }

    public function test_disabled_email_is_audited_and_can_be_sent_after_enabling(): void
    {
        app(SystemSettingsService::class)->put('store_email_enabled', false);
        $component = Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $this->order->id])->call('quickStatusByCode', 'paid');
        $notification = $this->order->notifications()->sole();
        $this->assertSame('skipped', $notification->status);
        $this->assertStringContainsString('isključeno', $notification->last_error);
        Mail::assertNothingSent();
        app(SystemSettingsService::class)->put('store_email_enabled', true);
        $component->call('retryNotification', $notification->id);
        Mail::assertSentCount(1);
    }

    public function test_invalid_email_and_log_transport_are_never_marked_sent(): void
    {
        $this->order->update(['customer_email' => 'invalid']);
        Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $this->order->id])->call('quickStatusByCode', 'paid');
        $this->assertStringContainsString('valjanu', $this->order->notifications()->sole()->last_error);
        $this->order->update(['customer_email' => 'kupac@example.test']);
        config(['mail.default' => 'log']);
        Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $this->order->id])->call('quickStatusByCode', 'new');
        $this->assertStringContainsString('ne dostavlja', $this->order->notifications()->first()->last_error);
        $this->assertSame(0, $this->order->notifications()->where('status', 'sent')->count());
        Mail::assertNothingSent();
    }

    public function test_rolled_back_status_events_never_send_mail(): void
    {
        try {
            DB::transaction(function (): void {
                $history = $this->order->history()->create(['from_status_id' => $this->order->status_id, 'to_status_id' => $this->paid->id]);
                app(OrderNotificationService::class)->statusChanged($history);
                Mail::assertNothingSent();
                throw new \RuntimeException('Roll back');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Roll back', $exception->getMessage());
        }
        $this->assertDatabaseCount('order_notifications', 0);
        Mail::assertNothingSent();
    }

    public function test_status_mail_uses_event_snapshot_and_registered_customer_link(): void
    {
        $this->order->update(['user_id' => User::factory()->create()->id]);
        DB::transaction(function (): void {
            $history = $this->order->history()->create(['from_status_id' => $this->order->status_id, 'to_status_id' => $this->paid->id]);
            app(OrderNotificationService::class)->statusChanged($history);
            $this->paid->update(['name' => 'Renamed later']);
            Mail::assertNothingSent();
        });
        Mail::assertSent(OrderUpdateMail::class, fn ($mail) => $mail->details['status_name'] === 'Plaćeno'
            && str_contains($mail->render(), route('account.orders.show', ['orderNumber' => 'MAIL-001'])));
    }

    public function test_tracking_is_sent_once_per_parcel_and_is_also_in_status_emails(): void
    {
        $this->order->update(['shipping_method_code' => 'gls', 'payload' => ['gls' => ['shipment' => ['parcel_number' => '000123456']]]]);
        $service = app(OrderNotificationService::class);
        $service->shipmentCreated($this->order);
        $service->shipmentCreated($this->order);
        Mail::assertSentCount(1);
        Mail::assertSent(OrderUpdateMail::class, fn ($mail) => $mail->details['tracking']['number'] === '000123456'
            && str_contains($mail->render(), 'Prati pošiljku'));

        Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $this->order->id])->call('quickStatusByCode', 'paid');
        Mail::assertSent(OrderUpdateMail::class, fn ($mail) => $mail->details['type'] === 'status' && $mail->details['tracking']['number'] === '000123456');
        $this->order->update(['payload' => ['gls' => ['shipment' => ['parcel_number' => '000987654']]]]);
        $service->shipmentCreated($this->order->fresh());
        Mail::assertSentCount(3);
    }

    public function test_no_tracking_email_is_created_without_an_api_parcel_number(): void
    {
        $this->order->update(['shipping_method_code' => 'gls', 'payload' => ['gls' => ['address_label' => true]]]);
        $this->assertNull(app(OrderNotificationService::class)->shipmentCreated($this->order));
        $this->assertDatabaseCount('order_notifications', 0);
        Mail::assertNothingSent();
    }

    public function test_retry_is_scoped_to_authorized_order(): void
    {
        app(SystemSettingsService::class)->put('store_email_enabled', false);
        Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $this->order->id])->call('quickStatusByCode', 'paid');
        $notification = OrderNotification::query()->sole();
        $another = $this->order->replicate();
        $another->order_number = 'MAIL-002';
        $another->save();
        Livewire::actingAs(User::factory()->create())->test(Show::class, ['orderId' => $this->order->id])->assertForbidden();
        Mail::assertNothingSent();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::actingAs($this->admin)->test(Show::class, ['orderId' => $another->id])->call('retryNotification', $notification->id);
    }
}
