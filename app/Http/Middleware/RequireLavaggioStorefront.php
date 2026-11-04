<?php

namespace App\Http\Middleware;

use App\Support\Lavaggio\PublicUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Croatian aliases share the ordinary controller route identity and policies. */
class RequireLavaggioStorefront
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(PublicUrls::enabled(), 404);
        $route = $request->route();
        $originalAction = $route?->getAction();
        if ($name = $route?->getAction('lavaggio_original_name')) {
            $route->setAction(array_replace($originalAction, ['as' => $name]));
        }

        try {
            return $next($request);
        } finally {
            // Keep the registered collection cacheable even in a long-lived process.
            if ($name) {
                $route->setAction($originalAction);
            }
        }
    }
}
