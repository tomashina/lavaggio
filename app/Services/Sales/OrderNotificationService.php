<?php

namespace App\Services\Sales;

use App\Mail\OrderUpdateMail;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderHistory;
use App\Models\Sales\Order\OrderNotification;
use App\Services\Front\StoreSettingsService;
use App\Support\GlsShipping;
use App\Support\Mail\StoreMailBrand;
use App\Support\OrderStatusLabels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;
use Throwable;

class OrderNotificationService
{
    public function __construct(private readonly StoreSettingsService $settings) {}

    public function statusChanged(OrderHistory $history): ?OrderNotification
    {
        if ($history->from_status_id === $history->to_status_id) {
            return null;
        }

        return $this->prepare($history->order, 'status', 'status:'.$history->id, $history);
    }

    public function shipmentCreated(Order $order): ?OrderNotification
    {
        $tracking = $this->tracking($order);
        if (! $tracking) {
            return null;
        }

        return $this->prepare($order, 'tracking', 'tracking:'.$order->id.':'.$tracking['carrier'].':'.$tracking['number']);
    }

    private function tracking(Order $order): ?array
    {
        if (GlsShipping::isGlsShippingMethod($order->shipping_method_code)) {
            $number = trim((string) data_get($order->payload, 'gls.shipment.parcel_number'));

            return $number !== '' ? ['carrier' => 'GLS', 'number' => $number, 'url' => 'https://gls-group.com/HR/hr/pracenje-paketa/'] : null;
        }

        $shipment = $order->boxNowShipment()->first();

        return $shipment?->parcel_id
            ? ['carrier' => 'BOX NOW', 'number' => $shipment->parcel_id, 'url' => $shipment->trackingUrl()]
            : null;
    }

    private function prepare(Order $order, string $type, string $key, ?OrderHistory $history = null): OrderNotification
    {
        // Snapshot the actual event: later status edits must not change an earlier email.
        $statusName = (string) ($history ? $history->toStatus?->name : $order->status?->name);
        $tracking = $this->tracking($order);
        $subject = 'Narudžba '.$order->order_number.' — '.($type === 'tracking' ? 'praćenje pošiljke' : $statusName);
        $notification = OrderNotification::query()->firstOrCreate(['event_key' => hash('sha256', $key)], [
            'order_id' => $order->id,
            'order_history_id' => $history?->id,
            'type' => $type,
            'recipient' => trim((string) $order->customer_email),
            'subject' => $subject,
            'content' => [
                'type' => $type,
                'store_name' => StoreMailBrand::usesSharedDesign() ? StoreMailBrand::name() : $this->settings->branding()['store_name'],
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'status_name' => $statusName,
                'status_description' => $type === 'status' ? OrderStatusLabels::customerMessage($statusName, 'hr') : null,
                'message' => $type === 'tracking'
                    ? 'Za Vašu narudžbu dodijeljen je broj za praćenje pošiljke.'
                    : 'Status Vaše narudžbe promijenjen je u: '.$statusName.'.',
                'tracking' => $tracking,
                'order_url' => $order->user_id ? route('account.orders.show', ['orderNumber' => $order->order_number]) : null,
            ],
        ]);

        if ($notification->wasRecentlyCreated || $notification->status === 'pending') {
            DB::afterCommit(fn () => $this->deliver($notification));
        }

        return $notification;
    }

    public function deliver(OrderNotification $notification, ?int $actorId = null): void
    {
        // Atomic claim prevents duplicate callbacks, concurrent clicks and webhook retries.
        $claimed = OrderNotification::query()->whereKey($notification->id)
            ->whereIn('status', ['pending', 'failed', 'skipped'])
            ->update(['status' => 'sending', 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }
        $notification->refresh();
        $status = 'failed';
        $error = null;

        try {
            $emailSettings = $this->settings->email();
            $transport = config('mail.mailers.'.config('mail.default').'.transport');
            if (! ($emailSettings['enabled'] ?? false)) {
                $status = 'skipped';
                $error = 'Slanje e-mailova isključeno je u postavkama trgovine.';
            } elseif (! filter_var($notification->recipient, FILTER_VALIDATE_EMAIL)) {
                $status = 'skipped';
                $error = 'Narudžba nema valjanu e-mail adresu kupca.';
            } elseif ($transport === 'log' || ($transport === 'array' && ! app()->runningUnitTests())) {
                $status = 'skipped';
                $error = 'Aktivni način slanja ne dostavlja e-mailove. Provjerite postavke e-maila.';
            } else {
                $mail = new OrderUpdateMail($notification->subject, $notification->content);
                $replyTo = trim((string) (($emailSettings['reply_to'] ?? '') ?: ($emailSettings['orders_to'] ?? '')));
                if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                    $mail->replyTo($replyTo);
                }
                $sent = Mail::to($notification->recipient)->send($mail);
                $status = $sent || Mail::getFacadeRoot() instanceof MailFake ? 'sent' : 'failed';
                if ($status === 'failed') {
                    $error = 'Slanje je zaustavljeno prije predaje poruke poslužitelju.';
                }
            }
        } catch (Throwable $exception) {
            // SMTP exceptions can contain credentials or the entire message; never expose them in admin.
            $error = 'E-mail nije poslan zbog greške pri slanju. Provjerite postavke e-maila i pokušajte ponovno.';
            Log::warning('Order notification failed.', ['notification_id' => $notification->id, 'exception_type' => $exception::class]);
        }

        $attempts = $notification->attempts ?? [];
        $attempts[] = ['at' => now()->toIso8601String(), 'status' => $status, 'error' => $error, 'by' => $actorId];
        $notification->update([
            'status' => $status, 'last_error' => $error, 'attempts' => $attempts,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
