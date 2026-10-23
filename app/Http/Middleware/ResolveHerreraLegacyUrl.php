<?php

namespace App\Http\Middleware;

use App\Services\Import\HerreraLegacyUrlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveHerreraLegacyUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $result = app(HerreraLegacyUrlService::class)->resolve($request);
        if ($result) {
            if ($result['destination']) {
                return redirect($result['destination'], 301);
            }

            $brand = config('storefront.home') === 'watchline' ? 'Watch Line' : 'Herrera';

            return response('<!doctype html><html lang="hr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sadržaj više nije dostupan | '.$brand.'</title></head><body><main><h1>Sadržaj više nije dostupan</h1><p>Sadržaj na ovoj adresi više nije dostupan.</p><a href="/shop">Pregledajte '.$brand.' katalog</a></main></body></html>', 410)->header('X-Robots-Tag', 'noindex');
        }

        return $next($request);
    }
}
