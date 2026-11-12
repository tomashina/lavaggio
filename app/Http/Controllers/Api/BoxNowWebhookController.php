<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Sales\Order\BoxNowShipment;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Integrations\BoxNow\BoxNowApiService;
use App\Services\Integrations\BoxNow\BoxNowShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoxNowWebhookController extends Controller
{
    public function __invoke(Request $request, ShippingMethod $shippingMethod, BoxNowApiService $api, BoxNowShipmentService $service): JsonResponse
    {
        $expected = $api->secret($shippingMethod, 'boxnow_webhook_token');
        abort_unless(strlen($expected) >= 32 && hash_equals($expected, (string) $request->bearerToken()), 401);
        $input = $request->validate([
            'data.parcelId' => ['required', 'string', 'max:120'],
            'data.orderNumber' => ['required', 'string', 'max:40'],
            'data.event' => ['required', 'string', 'max:60'],
            'data.time' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'data.eventLocation.displayName' => ['nullable', 'string', 'max:255'],
        ]);
        $data = $input['data'];
        $shipment = BoxNowShipment::query()->where('shipping_method_id', $shippingMethod->id)
            ->where('order_number', $data['orderNumber'])->where('parcel_id', $data['parcelId'])->first();
        // Returning a failure lets BOX NOW retry if the creation response is still in flight.
        abort_unless($shipment, 409);
        $service->recordEvent($shipment, $data['event'], $data['time'], data_get($data, 'eventLocation.displayName'), 'webhook');

        return response()->json(['ok' => true]);
    }
}
