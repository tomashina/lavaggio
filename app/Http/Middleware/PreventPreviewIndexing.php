<?php

namespace App\Http\Middleware;

use App\Support\SearchEngineIndexing;
use App\Support\WatchlineSeo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventPreviewIndexing
{
    public function handle(Request $request, Closure $next): Response
    {
        $localPreview = app()->environment('local')
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && ! $request->expectsJson();
        if ($localPreview) {
            // A rebuilt Vite bundle can remove the CSS referenced by old HTML.
            // Do not let catalog validators replay that document during design work.
            $request->headers->remove('If-None-Match');
            $request->headers->remove('If-Modified-Since');
        }

        $response = $next($request);

        if ($localPreview && str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->remove('ETag');
            $response->headers->remove('Last-Modified');
        }

        if ((SearchEngineIndexing::shouldBlock($request)
                || (config('storefront.home') === 'watchline' && WatchlineSeo::isPrivate($request)))
            && stripos((string) $response->headers->get('X-Robots-Tag'), 'noindex') === false) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
