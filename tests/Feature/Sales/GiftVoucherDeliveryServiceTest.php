<?php

namespace Tests\Feature\Sales;

use App\Mail\GiftVoucherDelivered;
use App\Mail\GiftVoucherPurchaseConfirmation;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\GiftVoucherMailDelivery;
use App\Models\Sales\Order\Order;
use App\Services\GiftVoucherDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class GiftVoucherDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gift_vouchers.enabled' => true, 'gift_vouchers.emails_enabled' => true, 'commerce.local_safe_mode' => true]);
        Mail::fake();
    }

    public function test_disabled_delivery_stays_waiting_then_sends_exactly_once(): void
    {
        $voucher = $this->voucher();
        $service = app(GiftVoucherDeliveryService::class);
        config(['gift_vouchers.emails_enabled' => false]);
        $this->assertSame('disabled', $service->send($voucher)['reason']);
        $this->assertSame('disabled', $service->send($voucher, true)['reason']);
        $this->assertDatabaseCount('gift_voucher_mail_deliveries', 1);
        $this->assertNull($voucher->fresh()->email_sent_at);
        Mail::assertNothingSent();
        config(['gift_vouchers.emails_enabled' => true]);
        $this->assertSame(1, $service->sendPending()['sent']);
        $this->assertSame(0, $service->sendPending()['sent']);
        $this->assertSame('processed', $service->send($voucher)['reason']);
        $this->assertNotNull($voucher->fresh()->email_sent_at);
        $this->assertDatabaseHas('gift_voucher_mail_deliveries', ['status' => 'sent', 'attempts' => 1]);
        Mail::assertSent(GiftVoucherDelivered::class, fn ($mail) => $mail->hasTo('primatelj@example.test') && $mail->voucher->code === $voucher->code);
        Mail::assertSentCount(1);
    }

    public function test_force_cannot_send_an_unpaid_disabled_expired_or_exhausted_voucher(): void
    {
        $unpaid = $this->order(['payload' => ['gift_voucher_purchase' => true]]);
        foreach ([['purchase_order_id' => $unpaid->id], ['status' => 'pending', 'issued_at' => null], ['status' => 'disabled'], ['expires_at' => now()->subDay()], ['status' => 'exhausted', 'balance' => 0]] as $attributes) {
            $this->assertSame('unavailable', app(GiftVoucherDeliveryService::class)->send($this->voucher($attributes), true)['reason']);
        }
        $this->assertDatabaseCount('gift_voucher_mail_deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_failed_explicit_resend_reuses_the_pending_delivery_and_does_not_leave_a_duplicate_retry(): void
    {
        $voucher = $this->voucher();
        $service = app(GiftVoucherDeliveryService::class);
        $this->assertTrue($service->send($voucher)['sent']);
        $mailFake = Mail::getFacadeRoot();
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new \RuntimeException('Private SMTP detail'));
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        Mail::shouldReceive('to')->once()->with($voucher->recipient_email)->andReturn($pending);
        $this->assertSame('failed', $service->send($voucher, true)['reason']);
        $this->assertDatabaseCount('gift_voucher_mail_deliveries', 2);
        $this->assertStringNotContainsString('Private SMTP detail', GiftVoucherMailDelivery::latest('id')->first()->last_error);
        Mail::swap($mailFake);
        $this->assertTrue($service->send($voucher, true)['sent']);
        $this->assertDatabaseCount('gift_voucher_mail_deliveries', 2);
        $this->assertSame(0, $service->sendPending()['sent']);
        $this->assertSame(0, GiftVoucherMailDelivery::where('status', 'failed')->count());
        Mail::assertSentCount(2);
    }

    public function test_ambiguous_processing_delivery_is_never_automatically_or_forcefully_duplicated(): void
    {
        $voucher = $this->voucher();
        GiftVoucherMailDelivery::create(['idempotency_key' => 'voucher:'.$voucher->id.':initial', 'kind' => 'voucher', 'gift_voucher_id' => $voucher->id,
            'recipient_email' => $voucher->recipient_email, 'status' => 'processing', 'claim_token' => Str::random(64), 'last_attempt_at' => now()->subDay()]);
        $service = app(GiftVoucherDeliveryService::class);
        $this->assertSame('processed', $service->send($voucher, true)['reason']);
        $this->assertSame(0, $service->sendPending()['checked']);
        $this->assertDatabaseCount('gift_voucher_mail_deliveries', 1);
        Mail::assertNothingSent();
    }

    public function test_a_marker_failure_after_provider_handoff_never_schedules_a_duplicate(): void
    {
        $voucher = $this->voucher();
        GiftVoucher::updating(function (GiftVoucher $row): void {
            if ($row->isDirty('email_sent_at')) {
                throw new \RuntimeException('Simulated marker persistence failure');
            }
        });
        $service = app(GiftVoucherDeliveryService::class);
        $this->assertSame('unconfirmed', $service->send($voucher)['reason']);
        $this->assertNull($voucher->fresh()->email_sent_at);
        $this->assertDatabaseHas('gift_voucher_mail_deliveries', ['gift_voucher_id' => $voucher->id, 'status' => 'sent']);
        $this->assertSame(0, $service->sendPending()['checked']);
        $this->assertSame('processed', $service->send($voucher)['reason']);
        Mail::assertSentCount(1);
    }

    public function test_disabling_a_queued_voucher_cancels_its_email_and_does_not_block_other_deliveries(): void
    {
        config(['gift_vouchers.emails_enabled' => false]);
        $voucher = $this->voucher();
        $other = $this->voucher(['recipient_email' => 'drugi@example.test']);
        $service = app(GiftVoucherDeliveryService::class);
        $service->send($voucher);
        $service->send($other);
        $voucher->update(['status' => 'disabled']);
        config(['gift_vouchers.emails_enabled' => true]);
        $this->assertSame(1, $service->sendPending()['sent']);
        $this->assertDatabaseHas('gift_voucher_mail_deliveries', ['gift_voucher_id' => $voucher->id, 'status' => 'cancelled']);
        $this->assertSame(0, $service->sendPending()['checked']);
        Mail::assertSentCount(1);
    }

    public function test_local_log_array_and_fallback_to_log_are_not_confirmed_as_real_delivery(): void
    {
        // Use the real factory only to inspect configuration; no send call is made.
        Mail::swap(new \Illuminate\Mail\MailManager($this->app));
        $service = app(GiftVoucherDeliveryService::class);
        config(['mail.default' => 'local', 'mail.mailers.local.transport' => 'log']);
        $this->assertFalse($service->canSend());
        config(['mail.mailers.local.transport' => 'array']);
        $this->assertFalse($service->canSend());
        config(['mail.mailers.local' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']], 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.log.transport' => 'log']);
        $this->assertFalse($service->canSend());
        config(['mail.mailers.local.mailers' => ['smtp']]);
        $this->assertTrue($service->canSend());
        config(['mail.mailers.local.mailers' => ['local']]);
        $this->assertFalse($service->canSend());
    }

    public function test_buyer_confirmation_is_idempotent_and_never_contains_the_voucher_code(): void
    {
        $order = $this->order(['payload' => ['gift_voucher_purchase' => true]]);
        $voucher = $this->voucher(['purchase_order_id' => $order->id, 'message' => '<script>alert(1)</script> Sretan rođendan!']);
        $service = app(GiftVoucherDeliveryService::class);
        $this->assertTrue($service->sendPurchaseConfirmation($order)['sent']);
        $this->assertSame('processed', $service->sendPurchaseConfirmation($order)['reason']);
        Mail::assertSent(GiftVoucherPurchaseConfirmation::class, fn ($mail) => $mail->hasTo('kupac@example.test'));
        Mail::assertSentCount(1);
        $buyerHtml = (new GiftVoucherPurchaseConfirmation($order))->render();
        $this->assertStringContainsString('signature=', $buyerHtml);
        $this->assertStringNotContainsString($voucher->code, $buyerHtml);
        $recipientHtml = (new GiftVoucherDelivered($voucher))->render();
        $this->assertStringContainsString($voucher->code, $recipientHtml);
        $this->assertStringContainsString('&lt;script&gt;', $recipientHtml);
        $this->assertStringNotContainsString('<script>', $recipientHtml);
        $this->assertStringContainsString('50,00', $recipientHtml);
    }

    public function test_queued_purchase_confirmation_is_cancelled_when_the_order_is_cancelled_before_retry(): void
    {
        $order = $this->order(['payload' => ['gift_voucher_purchase' => true]]);
        $service = app(GiftVoucherDeliveryService::class);
        config(['gift_vouchers.emails_enabled' => false]);
        $this->assertSame('disabled', $service->sendPurchaseConfirmation($order)['reason']);
        $this->assertDatabaseHas('gift_voucher_mail_deliveries', ['order_id' => $order->id, 'kind' => 'purchase', 'status' => 'waiting']);
        $cancelled = \App\Models\Settings\Local\OrderStatus::create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => true, 'is_active' => true]);
        $order->update(['status_id' => $cancelled->id]);
        config(['gift_vouchers.emails_enabled' => true]);
        $this->assertSame(0, $service->sendPending()['sent']);
        $this->assertDatabaseHas('gift_voucher_mail_deliveries', ['order_id' => $order->id, 'kind' => 'purchase', 'status' => 'cancelled', 'attempts' => 0]);
        $this->assertSame(0, $service->sendPending()['checked']);
        Mail::assertNothingSent();
    }

    public function test_purchase_cancellation_after_claim_is_rechecked_before_sending(): void
    {
        $order = $this->order(['payload' => ['gift_voucher_purchase' => true]]);
        $cancelled = \App\Models\Settings\Local\OrderStatus::create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => true, 'is_active' => true]);
        $cancelledDuringClaim = false;
        \Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($order, $cancelled, &$cancelledDuringClaim): void {
            if (! $cancelledDuringClaim && str_starts_with(strtolower($query->sql), 'update')
                && str_contains($query->sql, 'gift_voucher_mail_deliveries') && in_array('processing', $query->bindings, true)) {
                $cancelledDuringClaim = true;
                $order->update(['status_id' => $cancelled->id]);
            }
        });
        $result = app(GiftVoucherDeliveryService::class)->sendPurchaseConfirmation($order);
        $this->assertTrue($cancelledDuringClaim);
        $this->assertSame('unavailable', $result['reason']);
        $this->assertFalse($result['sent']);
        $this->assertDatabaseHas('gift_voucher_mail_deliveries', ['order_id' => $order->id, 'kind' => 'purchase', 'status' => 'cancelled', 'attempts' => 1, 'claim_token' => null]);
        Mail::assertNothingSent();
    }

    private function voucher(array $attributes = []): GiftVoucher
    {
        $voucher = new GiftVoucher(array_replace(['initial_amount' => 50, 'balance' => 50, 'currency' => 'EUR', 'recipient_name' => 'Primatelj',
            'recipient_email' => 'primatelj@example.test', 'sender_name' => 'Pošiljatelj', 'status' => 'active', 'issued_at' => now(), 'locale' => 'hr'], $attributes));
        if ($voucher->issued_at) {
            $voucher->storeCode('LIBER-'.Str::upper(Str::random(16)));
        }
        $voucher->save();

        return $voucher;
    }

    private function order(array $attributes = []): Order
    {
        return Order::create(array_replace(['order_number' => 'MAIL-'.Str::upper(Str::random(10)), 'source' => 'web', 'locale' => 'hr',
            'currency_code' => 'EUR', 'currency_rate' => 1, 'customer_name' => 'Kupac', 'customer_email' => 'kupac@example.test', 'grand_total' => 50, 'payload' => []], $attributes));
    }
}
