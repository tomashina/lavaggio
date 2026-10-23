<?php

namespace App\Services;

use App\Mail\GiftVoucherDelivered;
use App\Mail\GiftVoucherPurchaseConfirmation;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\GiftVoucherMailDelivery;
use App\Models\Sales\Order\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;

class GiftVoucherDeliveryService
{
    public function canSend(): bool
    {
        return (bool) config('gift_vouchers.enabled', true)
            && (bool) config('gift_vouchers.emails_enabled', false)
            && (Mail::getFacadeRoot() instanceof MailFake || $this->hasDeliveryTransport((string) config('mail.default')));
    }

    public function send(GiftVoucher $voucher, bool $force = false): array
    {
        if (! $this->deliverable($voucher->fresh())) {
            return $this->result(false, 'unavailable', 'Bon još nije izdan, nije aktivan ili je istekao.');
        }
        $delivery = DB::transaction(function () use ($voucher, $force): GiftVoucherMailDelivery {
            GiftVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($force) {
                $outstanding = GiftVoucherMailDelivery::where('gift_voucher_id', $voucher->id)
                    ->whereIn('status', ['waiting', 'failed', 'processing'])->oldest('id')->first();
                if ($outstanding) {
                    return $outstanding;
                }
            }

            return GiftVoucherMailDelivery::firstOrCreate([
                'idempotency_key' => 'voucher:'.$voucher->id.($force ? ':resend:'.Str::uuid() : ':initial'),
            ], [
                'kind' => 'voucher', 'gift_voucher_id' => $voucher->id, 'order_id' => $voucher->purchase_order_id,
                'recipient_email' => $voucher->recipient_email,
            ]);
        });

        return $this->sendDelivery($delivery->id);
    }

    public function sendPurchaseConfirmation(Order $order): array
    {
        if (! data_get($order->payload, 'gift_voucher_purchase')) {
            return $this->result(false, 'unavailable', 'Narudžba ne sadrži kupnju poklon bona.');
        }
        $delivery = GiftVoucherMailDelivery::firstOrCreate([
            'idempotency_key' => 'order:'.$order->id.':purchase',
        ], [
            'kind' => 'purchase', 'order_id' => $order->id, 'recipient_email' => $order->customer_email,
        ]);

        return $this->sendDelivery($delivery->id);
    }

    public function sendPending(int $limit = 50): array
    {
        $stats = ['checked' => 0, 'sent' => 0, 'failed' => 0, 'disabled' => 0];
        if (! $this->canSend()) {
            $stats['disabled'] = 1;

            return $stats;
        }
        $deliveries = GiftVoucherMailDelivery::where(function ($query): void {
            $query->where('status', 'waiting')->orWhere(fn ($query) => $query->where('status', 'failed')
                ->where('attempts', '<', max(1, (int) config('gift_vouchers.max_email_attempts', 3)))
                ->where('last_attempt_at', '<=', now()->subMinutes(5)));
        })->orderBy('id')->limit(max(1, min(500, $limit)))->get(['id']);
        foreach ($deliveries as $delivery) {
            $stats['checked']++;
            $result = $this->sendDelivery($delivery->id);
            if ($result['sent']) {
                $stats['sent']++;
            } elseif (in_array($result['reason'], ['failed', 'unconfirmed'], true)) {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    private function sendDelivery(int $id): array
    {
        if (! $this->canSend()) {
            return $this->result(false, 'disabled', 'Slanje e-pošte trenutačno nije uključeno ili postavljeno. Poruka ostaje na čekanju.');
        }
        $delivery = GiftVoucherMailDelivery::with(['voucher.purchaseOrder', 'order.status'])->find($id);
        if (! $delivery || in_array($delivery->status, ['sent', 'processing', 'cancelled'], true)) {
            return $this->result(false, 'processed', 'Poruka je već poslana ili se trenutačno obrađuje.');
        }
        if (! filter_var($delivery->recipient_email, FILTER_VALIDATE_EMAIL)) {
            $this->cancelDelivery($id, 'E-mail adresa nije ispravna.');

            return $this->result(false, 'invalid', 'E-mail adresa nije ispravna.');
        }
        if (($delivery->kind === 'voucher' && ! $this->deliverable($delivery->voucher))
            || ($delivery->kind === 'purchase' && ! $this->purchaseDeliverable($delivery->order))) {
            $this->cancelDelivery($id, 'Bon ili narudžba više nisu dostupni za slanje.');

            return $this->result(false, 'unavailable', 'Poruku za ovaj bon trenutačno nije moguće poslati.');
        }
        $claim = Str::random(64);
        if (! GiftVoucherMailDelivery::whereKey($id)->whereIn('status', ['waiting', 'failed'])->update([
            'status' => 'processing', 'claim_token' => $claim, 'attempts' => DB::raw('attempts + 1'),
            'last_attempt_at' => now(), 'updated_at' => now(),
        ])) {
            return $this->result(false, 'processed', 'Poruka se već šalje.');
        }

        try {
            $delivery->refresh()->load(['voucher.purchaseOrder', 'order.status']);
            if (($delivery->kind === 'voucher' && ! $this->deliverable($delivery->voucher))
                || ($delivery->kind === 'purchase' && ! $this->purchaseDeliverable($delivery->order))) {
                $this->claimed($id, $claim)->update(['status' => 'cancelled', 'claim_token' => null, 'last_error' => 'Bon ili narudžba više nisu dostupni za slanje.', 'updated_at' => now()]);

                return $this->result(false, 'unavailable', 'Bon ili narudžba više nisu dostupni. Poruka nije poslana.');
            }
            $mail = $delivery->kind === 'voucher' ? new GiftVoucherDelivered($delivery->voucher) : new GiftVoucherPurchaseConfirmation($delivery->order);
            $sent = Mail::to($delivery->recipient_email)->send($mail->locale($delivery->voucher?->locale ?: $delivery->order?->locale ?: 'hr'));
            if (! $sent && ! (Mail::getFacadeRoot() instanceof MailFake)) {
                throw new \RuntimeException('Slanje je zaustavljeno.');
            }
        } catch (\Throwable $exception) {
            $error = class_basename($exception).': Slanje nije uspjelo.';
            $this->claimed($id, $claim)->update(['status' => 'failed', 'claim_token' => null, 'last_error' => $error, 'updated_at' => now()]);
            $delivery->voucher?->forceFill(['email_error' => $error])->save();

            return $this->result(false, 'failed', 'Slanje nije uspjelo. Poruka je sačuvana za ponovni pokušaj.');
        }

        try {
            $this->claimed($id, $claim)->update(['status' => 'sent', 'sent_at' => now(), 'claim_token' => null, 'last_error' => null, 'updated_at' => now()]);
            $delivery->voucher?->forceFill([
                'email_sent_at' => $delivery->voucher->email_sent_at ?: now(), 'last_email_sent_at' => now(), 'email_error' => null,
            ])->save();

            return $this->result(true, null, 'E-mail je uspješno poslan.');
        } catch (\Throwable) {
            // A confirmed provider handoff must not become an automatic resend.
            return $this->result(false, 'unconfirmed', 'Poruka je predana za slanje. Zapis dostave nije potvrđen; provjerite ga prije ponovnog slanja.');
        }
    }

    private function purchaseDeliverable(?Order $order): bool
    {
        return $order && data_get($order->payload, 'gift_voucher_purchase') && ! $order->status?->is_cancelled;
    }

    private function deliverable(?GiftVoucher $voucher): bool
    {
        return $voucher && $voucher->issued_at && $voucher->code && $voucher->isUsable()
            && (! $voucher->purchase_order_id || $voucher->purchaseOrder?->paid_at);
    }

    private function claimed(int $id, string $claim)
    {
        return GiftVoucherMailDelivery::whereKey($id)->where('status', 'processing')->where('claim_token', $claim);
    }

    private function cancelDelivery(int $id, string $reason): void
    {
        GiftVoucherMailDelivery::whereKey($id)->whereIn('status', ['waiting', 'failed'])
            ->update(['status' => 'cancelled', 'claim_token' => null, 'last_error' => $reason, 'updated_at' => now()]);
    }

    private function hasDeliveryTransport(string $mailer, array $visited = []): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }
        $configuration = config('mail.mailers.'.$mailer, []);
        $transport = $configuration['transport'] ?? $mailer;
        if (in_array($transport, ['log', 'array', ''], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $mailers = $configuration['mailers'] ?? [];

            return $mailers !== [] && collect($mailers)->every(fn ($name) => $this->hasDeliveryTransport($name, [...$visited, $mailer]));
        }

        return true;
    }

    private function result(bool $sent, ?string $reason, string $message): array
    {
        return compact('sent', 'reason', 'message');
    }
}
