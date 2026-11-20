<?php

namespace App\Http\Middleware;

use App\Support\Lavaggio\PublicUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LavaggioPublicUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PublicUrls::enabled() || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        $path = '/'.trim($request->getPathInfo(), '/');
        $legacy = config('lavaggio_legacy_urls', []);
        $key = rawurldecode($path);
        if (PublicUrls::retiredSlug($path) || (array_key_exists($key, $legacy) && $legacy[$key] === null)) {
            return response()->view('errors.lavaggio-retired-service', [], 410)->header('X-Robots-Tag', 'noindex');
        }

        $target = $legacy[$key] ?? PublicUrls::url($path);
        $host = strtolower($request->getHost());
        $hosted = in_array($host, ['lavaggio.hr', 'www.lavaggio.hr', 'shop.lavaggio.hr'], true);
        $origin = null;
        if ($hosted && ! $request->query->has('signature') && ! $request->is('api/*', 'checkout/corvus/*', 'checkout/keks/*', 'checkout/wspay/*', 'account/impersonation/*')) {
            $origin = config('app.url') === 'https://www.lavaggio.hr' ? 'https://www.lavaggio.hr' : 'https://'.$host;
        }

        if ($target !== $path || ($origin !== null && ($origin !== $request->getSchemeAndHttpHost() || $path !== $request->getPathInfo()))) {
            $query = (string) $request->server->get('QUERY_STRING', '');

            return redirect(($origin ?? '').$target.($query !== '' ? '?'.$query : ''), 301);
        }

        return $next($request);
    }
}
