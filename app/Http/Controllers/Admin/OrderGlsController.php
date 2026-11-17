<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sales\Order\Order;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Integrations\Gls\GlsShipmentService;
use App\Services\Shipping\GlsAddressLabelPdf;
use App\Support\GlsShipping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderGlsController extends Controller
{
    public function addressLabel(Order $order, GlsAddressLabelPdf $pdf): Response|RedirectResponse
    {
        app(OrderManagerAccess::class)->assertOrder($order);
        abort_unless(GlsShipping::isGlsShippingMethod($order->shipping_method_code), 404);

        try {
            $contents = $pdf->render($order);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('admin.orders.show', $order)->with('notify', [
                'type' => 'error', 'message' => $exception->getMessage(),
            ]);
        }

        $filename = 'adresna-naljepnica-'.(Str::slug($order->order_number) ?: $order->id).'-100x150.pdf';

        return response($contents)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function send(Order $order, GlsShipmentService $gls): RedirectResponse
    {
        app(OrderManagerAccess::class)->assertOrder($order);
        try {
            $shipment = $gls->send($order, auth()->id());

            return redirect()->back()->with('notify', [
                'type' => 'success',
                'message' => 'GLS naljepnica je generirana. Broj paketa: '.($shipment['parcel_number'] ?? '-'),
            ]);
        } catch (\Throwable $exception) {
            return redirect()->back()->with('notify', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function label(Order $order, GlsShipmentService $gls): StreamedResponse|RedirectResponse
    {
        app(OrderManagerAccess::class)->assertOrder($order);
        try {
            return $gls->downloadLabel($order);
        } catch (\Throwable $exception) {
            return redirect()->back()->with('notify', [
                'type' => 'error',
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
