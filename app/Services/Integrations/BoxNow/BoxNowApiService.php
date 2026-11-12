<?php

namespace App\Services\Integrations\BoxNow;

use App\Models\Settings\Local\ShippingMethod;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BoxNowApiService
{
    public function assertConfigured(ShippingMethod $method): void
    {
        $this->baseUrl($method);
        if (trim((string) data_get($method->settings, 'boxnow_oauth_client_id')) === '' || $this->secret($method, 'boxnow_oauth_client_secret') === '') {
            throw new RuntimeException('U postavkama BOX NOW dostave unesite OAuth Client ID i Client Secret.');
        }
    }

    public function baseUrl(ShippingMethod $method): string
    {
        $url = rtrim(trim((string) data_get($method->settings, 'boxnow_api_url')), '/');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! preg_match('/\.boxnow\.(hr|gr|bg)$/', $host)
            || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT)) {
            throw new RuntimeException('U postavkama BOX NOW dostave unesite službeni HTTPS API URL koji ste dobili od BOX NOW-a.');
        }

        return str_ends_with($url, '/api/v1') ? $url : $url.'/api/v1';
    }

    public function secret(ShippingMethod $method, string $key): string
    {
        $encrypted = trim((string) data_get($method->settings, $key.'_encrypted'));
        if ($encrypted === '') {
            return '';
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            throw new RuntimeException('Spremljeni BOX NOW pristupni podatak nije moguće pročitati. Spremite ga ponovno u postavkama dostave.');
        }
    }

    public function create(ShippingMethod $method, array $payload): array
    {
        $response = $this->request($method, 'POST', '/delivery-requests', $payload);
        $data = $response->json();
        if (! is_array($data)) {
            throw new RuntimeException('BOX NOW nije vratio valjan odgovor. Prije ponovnog slanja provjerite postojeću pošiljku.');
        }

        return $data;
    }

    public function parcels(ShippingMethod $method, string $orderNumber, ?string $parcelId = null): array
    {
        $data = $this->request($method, 'GET', '/parcels', array_filter([
            'orderNumber' => $orderNumber, 'parcelId' => $parcelId, 'limit' => 100,
        ]))->json('data');
        if (! is_array($data) || ! array_is_list($data)) {
            throw new RuntimeException('BOX NOW nije vratio valjan popis pošiljaka.');
        }

        return $data;
    }

    public function label(ShippingMethod $method, string $parcelId): string
    {
        $pdf = $this->request($method, 'GET', '/parcels/'.rawurlencode($parcelId).'/label.pdf', [], 'application/pdf')->body();
        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('BOX NOW naljepnica trenutačno nije dostupna. Pokušajte ponovno preuzeti PDF.');
        }

        return $pdf;
    }

    private function request(ShippingMethod $method, string $verb, string $path, array $data = [], string $accept = 'application/json'): Response
    {
        $base = $this->baseUrl($method);
        $clientId = trim((string) data_get($method->settings, 'boxnow_oauth_client_id'));
        $secret = $this->secret($method, 'boxnow_oauth_client_secret');
        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('U postavkama BOX NOW dostave unesite OAuth Client ID i Client Secret.');
        }
        $cacheKey = 'boxnow:token:'.hash('sha256', $base.'|'.$clientId.'|'.$secret);

        try {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $token = Cache::remember($cacheKey, 3000, function () use ($base, $clientId, $secret): string {
                    $response = Http::acceptJson()->asJson()->connectTimeout(10)->timeout(30)
                        ->withoutRedirecting()->post($base.'/auth-sessions', [
                            'grant_type' => 'client_credentials', 'client_id' => $clientId, 'client_secret' => $secret,
                        ]);
                    $this->assertSuccess($response);
                    $token = $response->json('access_token');
                    if (! is_string($token) || $token === '') {
                        throw new RuntimeException('BOX NOW prijava nije vratila pristupni token.');
                    }

                    return $token;
                });

                $response = Http::withToken($token)->accept($accept)->asJson()->connectTimeout(10)->timeout(30)
                    ->withoutRedirecting()->send($verb, $base.$path, [$verb === 'GET' ? 'query' : 'json' => $data]);
                if ($response->status() !== 401 || $attempt === 1) {
                    $this->assertSuccess($response);

                    return $response;
                }
                Cache::forget($cacheKey);
            }
        } catch (ConnectionException) {
            // Never expose HTTP request bodies, credentials or customer data in errors.
            throw new RuntimeException('BOX NOW veza nije uspjela. Ishod slanja će se provjeriti prije novog pokušaja.');
        }

        throw new RuntimeException('BOX NOW prijava nije uspjela.');
    }

    private function assertSuccess(Response $response): void
    {
        if ($response->successful()) {
            return;
        }
        $code = (string) $response->json('code', '');
        $message = match ($code) {
            'P401' => 'Provjerite Warehouse ID u postavkama dostave.',
            'P402' => 'Odabrani paketomat nije valjan za ovu dostavu.',
            'P405' => 'Provjerite broj mobitela primatelja; potreban je međunarodni format.',
            'P406' => 'Veličina paketa nije valjana.',
            'P410' => 'Narudžba već ima BOX NOW pošiljku. Provjeravaju se postojeći podaci.',
            'P411' => 'BOX NOW pouzeće nije omogućeno za ovaj partnerski račun.',
            default => match ($response->status()) {
                401, 403 => 'Provjerite BOX NOW pristupne podatke i status partnerskog računa.',
                429 => 'BOX NOW je privremeno ograničio broj zahtjeva. Pokušajte kasnije.',
                default => 'BOX NOW zahtjev nije uspio (HTTP '.$response->status().'). Provjerite postavke i podatke narudžbe.',
            },
        };
        throw new BoxNowApiException($message, $code, in_array($response->status(), [400, 401, 403, 422, 429], true) && $code !== 'P410');
    }
}
