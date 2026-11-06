<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Reattach the buyer's SameSite=Lax session before handling a Corvus cancellation. */
class CorvusCancelPostback
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('checkout.corvus.cancel', 'checkout.corvus.cancel.static')) {
            return $next($request);
        }

        $query = [];
        foreach (['expires', 'signature', 'order_number', 'language'] as $field) {
            $value = $request->query->all()[$field] ?? null;
            if (is_string($value)) {
                $query[$field] = $value;
            }
        }
        // The cancellation protocol adds only these fields. The application URL's
        // signature and expiry must stay in the query and cannot be replaced by POST.
        foreach (['order_number', 'language'] as $field) {
            $value = $request->request->all()[$field] ?? null;
            if (is_string($value) && ! array_key_exists($field, $query)) {
                $query[$field] = $value;
            }
        }
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return redirect($request->url().($queryString !== '' ? '?'.$queryString : ''), 303)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
