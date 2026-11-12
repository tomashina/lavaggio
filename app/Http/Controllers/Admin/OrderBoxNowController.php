<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sales\Order\Order;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Integrations\BoxNow\BoxNowShipmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderBoxNowController extends Controller
{
    public function send(Request $request, Order $order, BoxNowShipmentService $service): RedirectResponse
    {
        app(OrderManagerAccess::class)->assertOrder($order);
        $data = $request->validate(['parcel_size' => ['required', 'integer', 'in:1,2,3']], [
            'parcel_size.required' => 'Odaberite veličinu paketa.',
            'parcel_size.integer' => 'Odaberite malu, srednju ili veliku veličinu paketa.',
            'parcel_size.in' => 'Odaberite malu, srednju ili veliku veličinu paketa.',
        ]);
        try {
            $shipment = $service->send($order, (int) $data['parcel_size'], auth()->id());

            return back()->with('notify', ['type' => 'success', 'message' => 'BOX NOW pošiljka je spremljena. Broj paketa: '.$shipment->parcel_id.'. Preuzmite PDF naljepnicu.']);
        } catch (\Throwable $e) {
            return back()->withInput($request->only('parcel_size'))->with('notify', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function sync(Order $order, BoxNowShipmentService $service): RedirectResponse
    {
        app(OrderManagerAccess::class)->assertOrder($order);
        abort_unless($order->boxNowShipment, 404);
        try {
            $service->sync($order->boxNowShipment, auth()->id());

            return back()->with('notify', ['type' => 'success', 'message' => 'BOX NOW status je osvježen i vidljiv kupcu.']);
        } catch (\Throwable $e) {
            return back()->with('notify', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    public function label(Order $order, BoxNowShipmentService $service): StreamedResponse|RedirectResponse
    {
        app(OrderManagerAccess::class)->assertOrder($order);
        abort_unless($order->boxNowShipment, 404);
        try {
            return $service->downloadLabel($order->boxNowShipment);
        } catch (\Throwable $e) {
            return back()->with('notify', ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }
}
