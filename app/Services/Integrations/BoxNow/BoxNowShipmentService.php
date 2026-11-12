<?php

namespace App\Services\Integrations\BoxNow;

use App\Models\Sales\Order\BoxNowShipment;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Settings\SystemSettingsService;
use App\Support\BoxNowStatus;
use App\Support\OrderStatusClassification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class BoxNowShipmentService
{
    public function __construct(private readonly BoxNowApiService $api) {}

    public function send(Order $order, ?int $actorId = null): BoxNowShipment
    {
        return Cache::lock('boxnow:order:'.$order->id, 300)->block(2, function () use ($order, $actorId): BoxNowShipment {
            $order->refresh();
            $method = ShippingMethod::query()->where('code', $order->shipping_method_code)->first();
            if (! $method || ($method->carrier !== 'boxnow' && ! in_array($method->code, ['boxnow', 'box_now'], true))) {
                throw new RuntimeException('Ova narudžba nema BOX NOW dostavu.');
            }
            $shipment = BoxNowShipment::query()->where('order_id', $order->id)->first();
            if ($shipment?->parcel_id) {
                return $shipment;
            }
            if ($order->status?->is_cancelled || in_array(strtolower((string) $order->status?->code), [...OrderStatusClassification::CANCELLED_NAMES, 'refunded'], true)) {
                throw new RuntimeException('Otkazana narudžba ne može se poslati.');
            }
            $base = $this->api->baseUrl($method);
            $this->api->assertConfigured($method);
            $request = $this->buildRequest($order, $method);
            $shipment ??= BoxNowShipment::query()->create([
                'order_id' => $order->id, 'shipping_method_id' => $method->id,
                'order_number' => $order->order_number, 'api_url' => $base,
            ]);
            $this->method($shipment);

            try {
                // A timeout or a crash may have happened AFTER BOX NOW accepted the request.
                // Reconcile the fixed order number; never generate a second order number.
                if ($shipment->attempted_at) {
                    return $this->recover($shipment, $actorId);
                }
                $shipment->update(['attempted_at' => now(), 'last_error' => null]);
                try {
                    $response = $this->api->create($method, $request);
                } catch (BoxNowApiException $exception) {
                    if ($exception->apiCode === 'P410') {
                        return $this->recover($shipment, $actorId);
                    }
                    if ($exception->rejected) {
                        $shipment->update(['attempted_at' => null]);
                    }
                    throw $exception;
                }
                $parcelId = trim((string) data_get($response, 'parcels.0.id'));
                if ($parcelId === '' || count($response['parcels'] ?? []) !== 1) {
                    throw new RuntimeException('BOX NOW nije vratio očekivani broj paketa. Provjerite postojeću pošiljku prije ponovnog slanja.');
                }
                $shipment->update(['parcel_id' => $parcelId, 'reference_number' => $response['referenceNumber'] ?? null, 'last_error' => null]);
                $this->recordEvent($shipment, 'new', now()->toIso8601String(), null, 'admin', $actorId);

                return $shipment->refresh();
            } catch (\Throwable $exception) {
                $shipment->update(['last_error' => $exception->getMessage()]);
                throw $exception;
            }
        });
    }

    public function sync(BoxNowShipment $shipment, ?int $actorId = null): BoxNowShipment
    {
        return Cache::lock('boxnow:order:'.$shipment->order_id, 300)->block(2, function () use ($shipment, $actorId): BoxNowShipment {
            $shipment->refresh();
            try {
                if (! $shipment->parcel_id) {
                    return $this->recover($shipment, $actorId);
                }
                $observedAt = now()->toIso8601String();
                $parcels = $this->api->parcels($this->method($shipment), $shipment->order_number, $shipment->parcel_id);
                $parcel = collect($parcels)->first(fn ($parcel) => (string) ($parcel['id'] ?? '') === $shipment->parcel_id);
                if (! $parcel) {
                    throw new RuntimeException('BOX NOW još nije vratio podatke za ovu pošiljku.');
                }
                $this->importEvents($shipment, $parcel, $observedAt, $actorId);
                $shipment->update(['last_synced_at' => now(), 'last_error' => null]);

                return $shipment->refresh();
            } catch (\Throwable $exception) {
                $shipment->update(['last_synced_at' => now(), 'last_error' => $exception->getMessage()]);
                throw $exception;
            }
        });
    }

    private function recover(BoxNowShipment $shipment, ?int $actorId): BoxNowShipment
    {
        $parcels = $this->api->parcels($this->method($shipment), $shipment->order_number);
        $parcels = array_values(array_filter($parcels, fn ($parcel) => (string) data_get($parcel, 'deliveryRequest.orderNumber') === $shipment->order_number));
        if (count($parcels) !== 1 || empty($parcels[0]['id'])) {
            throw new RuntimeException('Ishod prethodnog slanja nije potvrđen. Kliknite „Osvježi status” za provjeru. Novi paket neće biti kreiran dok se postojeći pokušaj ne razriješi s BOX NOW podrškom.');
        }
        $shipment->update(['parcel_id' => (string) $parcels[0]['id'], 'last_error' => null, 'last_synced_at' => now()]);
        $this->importEvents($shipment, $parcels[0], now()->toIso8601String(), $actorId);

        return $shipment->refresh();
    }

    private function importEvents(BoxNowShipment $shipment, array $parcel, string $observedAt, ?int $actorId): void
    {
        $events = collect($parcel['events'] ?? [])->filter(fn ($event) => is_array($event) && ! empty($event['type']) && ! empty($event['createTime']))->sortBy('createTime');
        foreach ($events as $event) {
            $this->recordEvent($shipment, (string) $event['type'], (string) $event['createTime'], $event['locationDisplayName'] ?? null, 'poll', $actorId);
        }
        if ($events->isEmpty() && ! empty($parcel['state']) && BoxNowStatus::normalize($parcel['state']) !== $shipment->fresh()->state) {
            $this->recordEvent($shipment, (string) $parcel['state'], $observedAt, null, 'poll', $actorId);
        }
    }

    public function recordEvent(BoxNowShipment $shipment, string $state, string $time, ?string $location, string $source, ?int $actorId = null): void
    {
        $state = BoxNowStatus::normalize($state);
        $at = CarbonImmutable::parse($time)->utc();
        if ($at->isAfter(now()->addMinutes(5))) {
            throw new RuntimeException('BOX NOW događaj ima neispravno vrijeme.');
        }
        $key = hash('sha256', $state.'|'.$at->format('Y-m-d\TH:i:s.u\Z'));
        DB::transaction(function () use ($shipment, $state, $at, $key, $location, $source, $actorId): void {
            $order = Order::query()->lockForUpdate()->findOrFail($shipment->order_id);
            $locked = BoxNowShipment::query()->lockForUpdate()->findOrFail($shipment->id);
            if ($locked->events()->where('event_key', $key)->exists()) {
                return;
            }
            $locked->events()->create([
                'event_key' => $key, 'state' => $state, 'occurred_at' => $at,
                'location' => $location ? mb_substr($location, 0, 255) : null, 'source' => $source,
            ]);
            $from = $order->status_id;
            $current = ! $locked->state_at || $at->greaterThan($locked->state_at);
            // Terminal deliveries cannot regress to an earlier shipping stage.
            if (in_array($locked->state, ['delivered', 'returned', 'canceled'], true)
                && in_array($state, ['new', 'in-transit', 'in-depot', 'final-destination', 'accepted-to-locker', 'wait-for-load'], true)) {
                $current = false;
            }
            if ($current && BoxNowStatus::orderStatus($state)) {
                $locked->update(['state' => $state, 'state_at' => $at, 'last_error' => null]);
                $status = $order->status;
                $protected = $status?->is_cancelled || in_array(strtolower((string) $status?->code), [...OrderStatusClassification::CANCELLED_NAMES, 'refunded', 'chargeback', 'completed', 'complete'], true);
                if (! $protected) {
                    $target = OrderStatus::query()->firstOrCreate(['code' => BoxNowStatus::orderStatus($state)], [
                        'name' => BoxNowStatus::orderLabel($state), 'color' => 'cyan', 'sort_order' => 100,
                        'is_active' => true, 'is_paid' => false, 'is_cancelled' => false, 'is_default' => false,
                    ]);
                    if ($target->is_active) {
                        // Do not infer payment from parcel movement (including COD deliveries).
                        $order->status_id = $target->id;
                        if ($actorId) {
                            $order->updated_by = $actorId;
                        }
                        $order->save();
                    }
                }
            }
            $history = $order->history()->make([
                'from_status_id' => $from, 'to_status_id' => $order->status_id, 'changed_by' => $actorId,
                'comment' => 'BOX NOW: '.BoxNowStatus::label($state).'.',
                'payload' => ['origin' => 'boxnow', 'event_key' => $key, 'state' => $state, 'occurred_at' => $at->toIso8601String(), 'current' => $current],
            ]);
            $history->created_at = $at;
            $history->save();
        });
    }

    public function downloadLabel(BoxNowShipment $shipment): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! $shipment->parcel_id) {
            throw new RuntimeException('Najprije kreirajte BOX NOW pošiljku.');
        }
        $path = 'boxnow/labels/'.$shipment->id.'.pdf';
        if (! Storage::disk('local')->exists($path)) {
            $contents = $this->api->label($this->method($shipment), $shipment->parcel_id);
            if (! Storage::disk('local')->put($path, $contents)) {
                throw new RuntimeException('Naljepnicu nije moguće spremiti. Pokušajte ponovno preuzeti PDF.');
            }
            $shipment->update(['label_path' => $path]);
        }

        return Storage::disk('local')->download($path, 'boxnow-'.$shipment->id.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function method(BoxNowShipment $shipment): ShippingMethod
    {
        $method = $shipment->shippingMethod;
        if (! $method || $this->api->baseUrl($method) !== $shipment->api_url) {
            throw new RuntimeException('BOX NOW postavke su promijenjene. Za ovu pošiljku vratite izvorni API URL i partnerske podatke.');
        }

        return $method;
    }

    private function buildRequest(Order $order, ShippingMethod $method): array
    {
        $warehouse = trim((string) data_get($method->settings, 'boxnow_warehouse_id'));
        $locker = trim((string) data_get($order->payload, 'shipping.boxnow.locker_id'));
        $name = trim($order->shipping_first_name.' '.$order->shipping_last_name) ?: trim((string) $order->customer_name);
        $phone = preg_replace('/[\s().-]+/', '', (string) $order->customer_phone);
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        } elseif (str_starts_with($phone, '0') && strtoupper((string) $order->shipping_country_code) === 'HR') {
            $phone = '+385'.substr($phone, 1);
        }
        if ($warehouse === '' || $locker === '') {
            throw new RuntimeException('Za BOX NOW slanje potreban je Warehouse ID u postavkama i odabrani paketomat u narudžbi.');
        }
        if ($name === '' || ! filter_var($order->customer_email, FILTER_VALIDATE_EMAIL) || ! preg_match('/^\+[1-9][0-9]{7,14}$/', $phone)) {
            throw new RuntimeException('Za BOX NOW slanje potrebni su ime, valjan e-mail i međunarodni broj mobitela primatelja.');
        }
        if (strtoupper((string) $order->currency_code) !== 'EUR') {
            throw new RuntimeException('BOX NOW Hrvatska slanje podržava narudžbe u EUR.');
        }
        $cod = ! $order->paid_at && in_array(strtolower((string) $order->payment_method_code), ['cod', 'cash_on_delivery'], true);
        $amount = number_format((float) $order->grand_total, 2, '.', '');
        $settings = app(SystemSettingsService::class);

        return [
            'orderNumber' => $order->order_number, 'invoiceValue' => $amount,
            'paymentMode' => $cod ? 'cod' : 'prepaid', 'amountToBeCollected' => $cod ? $amount : '0.00',
            'allowReturn' => true,
            'origin' => [
                'contactName' => (string) $settings->get('store_brand_name', config('app.name')),
                'locationId' => $warehouse,
            ],
            'destination' => ['contactName' => $name, 'contactEmail' => $order->customer_email, 'contactNumber' => $phone, 'locationId' => $locker],
            // API items represent parcels, NOT the individual products in the basket.
            'items' => [['id' => $order->order_number.'-1', 'name' => 'Narudžba '.$order->order_number, 'value' => $amount, 'weight' => 0]],
        ];
    }
}
