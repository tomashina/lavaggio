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
        if (PublicUrls::enabled() && in_array($request->method(), ['GET', 'HEAD'], true)) {
            $path = '/'.$request->path();
            if (PublicUrls::retiredSlug($path)) {
                return response()->view('errors.lavaggio-retired-service', [], 410)->header('X-Robots-Tag', 'noindex');
            }
            $target = PublicUrls::url($path);
            if ($target !== $path) {
                $query = $request->getQueryString();

                return redirect($target.($query ? '?'.$query : ''), 301);
            }
        }

        return $next($request);
    }
}
