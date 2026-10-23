<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Integrations\Watchline\AbandonedCart;
use App\Models\Integrations\Watchline\MessageDelivery;
use App\Services\Front\CartService;
use App\Services\Integrations\Watchline\WatchlineMessageAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WatchlineMessageAutomationController extends Controller
{
    public function capture(Request $request, WatchlineMessageAutomationService $automations): JsonResponse
    {
        abort_unless(config('storefront.home') === 'watchline', 404);
        if (! $automations->cartCaptureEnabled()) {
            return response()->json(['ok' => true, 'captured' => false])->header('Cache-Control', 'private, no-store');
        }
        $validated = $request->validate(['email' => ['required', 'email', 'max:191'], 'name' => ['nullable', 'string', 'max:120'], 'consent' => ['required', 'boolean']]);
        $request->session()->put('watchline.cart_contact', $validated['consent'] ? $validated : ['consent' => false]);
        $automations->captureCart($request);

        return response()->json(['ok' => true])->header('Cache-Control', 'private, no-store');
    }

    public function recover(Request $request, AbandonedCart $cart, CartService $cartService): RedirectResponse
    {
        abort_unless(config('storefront.home') === 'watchline' && ! $cart->converted_at && ! $cart->closed_at, 404);
        $delivery = MessageDelivery::query()->where('job', 'abandoned')->where('target_id', $cart->id)->where('status', 'sent')->latest('id')->first();
        $couponId = (int) data_get($delivery?->payload, 'coupon_action_id');
        $coupon = $couponId ? \App\Models\Catalog\Action\CatalogAction::query()->active()->find($couponId)?->coupon_code : null;
        $cartService->replaceRaw($cart->items, $coupon);
        $cart->update(['recovered_at' => now()]);

        return redirect()->route('cart.index')->header('Cache-Control', 'private, no-store');
    }

    public function unsubscribe(string $job, string $hash, WatchlineMessageAutomationService $automations): Response
    {
        abort_unless(config('storefront.home') === 'watchline', 404);
        $automations->unsubscribe($job, $hash);

        return response('Odjava je spremljena. Više vam nećemo slati ovu vrstu poruke.', 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8')->header('Cache-Control', 'private, no-store');
    }
}
