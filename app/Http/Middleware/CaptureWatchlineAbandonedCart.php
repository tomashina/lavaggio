<?php

namespace App\Http\Middleware;

use App\Services\Integrations\Watchline\WatchlineMessageAutomationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureWatchlineAbandonedCart
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (config('storefront.home') === 'watchline' && $response->getStatusCode() < 400
            && $request->routeIs('cart.*', 'checkout.create', 'checkout.options')) {
            app(WatchlineMessageAutomationService::class)->captureCart($request);
        }

        return $response;
    }
}
