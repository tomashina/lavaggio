<?php

namespace App\Support\Lavaggio;

/** Public storefront addresses; payment providers and signed links keep their protocol URLs. */
class PublicUrls
{
    private const PREFIXES = [
        'pages/category' => 'stranice/kategorija',
        'search/autocomplete' => 'pretraga/prijedlozi',
        'auth/forgot-password' => 'prijava/zaboravljena-lozinka',
        'auth/reset-password' => 'prijava/obnova-lozinke',
        'auth/b2b-register' => 'prijava/registracija-tvrtke',
        'auth/register' => 'prijava/registracija',
        'auth/login' => 'prijava',
        'checkout/success' => 'naplata/potvrda',
        'checkout/options' => 'naplata/opcije',
        'checkout/login' => 'naplata/prijava',
        'cart/preview' => 'kosarica/pregled',
        'cart/items' => 'kosarica/stavke',
        'cart/coupon' => 'kosarica/kupon',
        'cart/gift-voucher' => 'kosarica/poklon-bon',
        'wishlist/toggle' => 'lista-zelja/promjena',
        'wishlist/items' => 'lista-zelja/stavke',
        'account/orders' => 'moj-racun/narudzbe',
        'account/statistics' => 'moj-racun/statistika',
        'account/customer-offers' => 'moj-racun/ponude',
        'account/loyalty' => 'moj-racun/vjernost',
        'account/profile' => 'moj-racun/profil',
        'account/preferences' => 'moj-racun/postavke',
        'account/addresses' => 'moj-racun/adrese',
        'account' => 'moj-racun',
        'categories' => 'kategorije',
        'category' => 'kategorija',
        'product' => 'proizvod',
        'manufacturer' => 'brend',
        'page' => 'stranica',
        'contact' => 'kontakt',
        'faq' => 'pitanja-i-odgovori',
        'shop' => 'trgovina',
        'cart' => 'kosarica',
        'wishlist' => 'lista-zelja',
        'checkout' => 'naplata',
    ];

    public static function enabled(): bool
    {
        return config('storefront.home') === 'lavaggio';
    }

    public static function uri(string $uri): string
    {
        return self::enabled() ? self::localizedUri($uri) : $uri;
    }

    public static function localizedUri(string $uri): string
    {
        if (preg_match('#^(?:checkout/(?:wspay|corvus|keks)(?:/|$)|account/impersonation/)#', $uri)) {
            return $uri;
        }
        foreach (self::PREFIXES as $from => $to) {
            if ($uri === $from || str_starts_with($uri, $from.'/')) {
                return $to.substr($uri, strlen($from));
            }
        }

        return $uri;
    }

    /** Only local/same-site URLs are rewritten; query strings and anchors are retained. */
    public static function url(string $url): string
    {
        if (! self::enabled() || ! preg_match('#^(?:/|https?://)#i', $url) || str_starts_with($url, '//')) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return $url;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $localHosts = array_filter([strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)), strtolower((string) request()->getHost()), 'shop.lavaggio.hr']);
        if ($host !== '' && ! in_array($host, $localHosts, true)) {
            return $url;
        }
        $path = '/'.self::uri(ltrim((string) ($parts['path'] ?? ''), '/'));
        if (in_array(rtrim($path, '/'), ['/stranica/cjenik', '/stranica/cjenik-usluzno-pranje'], true)) {
            $path = '/cjenik';
        }
        $prefix = isset($parts['host']) ? (($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')) : '';

        return $prefix.$path.(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    public static function registerAliases(): void
    {
        $router = app('router');
        foreach (iterator_to_array($router->getRoutes()) as $route) {
            $uri = self::localizedUri($route->uri());
            if ($uri === $route->uri()) {
                continue;
            }
            $alias = clone $route;
            $alias->setUri($uri);
            $action = $alias->getAction();
            // The alias URI is complete. Cached routes must not reapply the original English group prefix.
            unset($action['prefix']);
            $action['lavaggio_original_name'] = $route->getName();
            $action['as'] = 'lavaggio.localized.'.($route->getName() ?: md5($route->uri()));
            $action['middleware'] = [\App\Http\Middleware\RequireLavaggioStorefront::class, ...($action['middleware'] ?? [])];
            $alias->setAction($action);
            $router->getRoutes()->add($alias);
        }
    }

    public static function retiredUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $localHosts = array_filter([strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)), strtolower((string) request()->getHost()), 'shop.lavaggio.hr']);
        $local = str_starts_with($url, '/') && ! str_starts_with($url, '//') || preg_match('#^https?://#i', $url) && in_array($host, $localHosts, true);

        return $local && self::retiredSlug((string) parse_url($url, PHP_URL_PATH));
    }

    public static function retiredSlug(string $path): bool
    {
        return (bool) preg_match('#^/(?:page|stranica)/(?:samoposluzna-praonica-rublja|kako-prati-rublje-kod-nas|kako-pripremiti-rublje-za-pranje|koji-program-za-pranje-odabrati|cjenik-samoposluzna-praonica|poklon-bon-lavaggio)/?$#', $path);
    }
}
