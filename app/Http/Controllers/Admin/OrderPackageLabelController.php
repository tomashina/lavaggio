<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sales\Order\Order;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Shipping\GlsAddressLabelPdf;
use App\Services\Shipping\PackageLabelPdf;
use App\Support\GlsShipping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrderPackageLabelController extends Controller
{
    public function __invoke(Request $request, Order $order, GlsAddressLabelPdf $gls, PackageLabelPdf $pdf): Response|RedirectResponse
    {
        app(OrderManagerAccess::class)->assertOrder($order);
        $pickup = $request->routeIs('admin.orders.pickup-label');
        abort_unless($pickup
            ? config('storefront.home') === 'lavaggio' && $order->isStorePickup()
            : GlsShipping::isGlsShippingMethod($order->shipping_method_code), 404);

        try {
            $contents = $pickup
                ? $pdf->render('admin.orders.pickup-label', ['order' => $order])
                : $gls->render($order);
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('admin.orders.show', $order)->with('notify', [
                'type' => 'error', 'message' => $exception->getMessage(),
            ]);
        }

        $filename = ($pickup ? 'preuzimanje-' : 'adresna-naljepnica-').(Str::slug($order->order_number) ?: $order->id).'-100x150.pdf';

        return response($contents)->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
