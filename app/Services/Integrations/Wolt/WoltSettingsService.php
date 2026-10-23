<?php

namespace App\Services\Integrations\Wolt;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WoltSettingsService
{
    public const OPERATIONS = ['catalog', 'inventory'];

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function options(): array
    {
        return array_replace([
            'environment' => 'stage',
            'auth_type' => 'legacy_basic',
            'access_token_expires_at' => null,
            'catalog_venue_id' => '',
            'inventory_venue_id' => '',
            'sku_field' => 'sku',
            'scope' => 'selected',
            'product_ids' => [],
            'price_basis' => 'current_gross',
            'availability_basis' => 'product_status',
            'include_vat' => true,
            'mapping_confirmed' => false,
            'auth_confirmed' => false,
            'send_enabled' => false,
        ], (array) $this->settings->get('watchline_wolt', []));
    }

    public function save(array $options, #[\SensitiveParameter] array $credentials = []): void
    {
        Validator::make($credentials, [
            'username' => ['nullable', 'string', 'max:1024'],
            'password' => ['nullable', 'string', 'max:4096'],
            'access_token' => ['nullable', 'string', 'max:8192'],
        ])->validate();
        $validated = Validator::make($options, [
            'environment' => ['required', Rule::in(['stage', 'production'])],
            'auth_type' => ['required', Rule::in(['legacy_basic', 'bearer'])],
            'access_token_expires_at' => ['nullable', 'date'],
            'catalog_venue_id' => ['nullable', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9_-]*\z/'],
            'inventory_venue_id' => ['nullable', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9_-]*\z/'],
            'sku_field' => ['required', Rule::in(['sku', 'code', 'barcode', 'source_product_id', 'opencart_model', 'explicit'])],
            'scope' => ['required', Rule::in(['selected', 'source_imported'])],
            'price_basis' => ['required', Rule::in(['current_gross', 'stored_base'])],
            'availability_basis' => ['required', Rule::in(['product_status', 'stock_quantity'])],
            'include_vat' => ['required', 'boolean'],
            'product_ids' => ['present', 'array', 'max:10000'],
            'product_ids.*' => ['integer', 'min:1', 'distinct', 'exists:products,id'],
            'mapping_confirmed' => ['required', 'boolean'],
            'auth_confirmed' => ['required', 'boolean'],
            'send_enabled' => ['required', 'boolean'],
        ])->validate();
        $validated['product_ids'] = array_values(array_unique(array_map('intval', $validated['product_ids'])));
        foreach (['catalog_venue_id', 'inventory_venue_id'] as $key) {
            $validated[$key] = trim((string) ($validated[$key] ?? ''));
        }
        $this->settings->put('watchline_wolt', $validated);

        $current = $this->credentials();
        foreach (['username', 'password', 'access_token'] as $key) {
            $value = (string) ($credentials[$key] ?? '');
            if ($key !== 'password') {
                $value = trim($value);
            }
            if ($value !== '') {
                $current[$key] = $value;
            }
        }
        if ($current !== []) {
            $this->settings->put('watchline_wolt_credentials', Crypt::encryptString(json_encode($current, JSON_THROW_ON_ERROR)));
        }
    }

    /** Secrets are only read by the transport; never hydrate them into Livewire state. */
    public function credentials(): array
    {
        $encrypted = (string) $this->settings->get('watchline_wolt_credentials', '');
        if ($encrypted === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public function credentialsConfigured(): bool
    {
        $credentials = $this->credentials();

        return $this->options()['auth_type'] === 'bearer'
            ? trim((string) ($credentials['access_token'] ?? '')) !== ''
            : trim((string) ($credentials['username'] ?? '')) !== '' && trim((string) ($credentials['password'] ?? '')) !== '';
    }

    public function localPreviewOnly(): bool
    {
        return app()->environment('local') || (bool) config('commerce.local_safe_mode', false);
    }

    public function sendBlockers(?string $operation = null): array
    {
        $options = $this->options();
        $blockers = [];
        if (config('storefront.home') !== 'watchline') {
            $blockers[] = 'Wolt povezivanje je dostupno samo za Watchline trgovinu.';
        }
        if ($this->localPreviewOnly()) {
            $blockers[] = 'Lokalni pregled: slanje u Wolt je isključeno.';
        }
        if (! $options['send_enabled']) {
            $blockers[] = 'Slanje u Wolt nije uključeno.';
        }
        if (! $options['mapping_confirmed']) {
            $blockers[] = 'Treba potvrditi da odabrane šifre odgovaraju postojećem Wolt asortimanu.';
        }
        if (! $options['auth_confirmed']) {
            $blockers[] = 'Treba potvrditi način prijave za postojeću Wolt integraciju.';
        }
        if (($operation !== null && ($options[$operation.'_venue_id'] ?? '') === '')
            || ($operation === null && ($options['catalog_venue_id'] === '' || $options['inventory_venue_id'] === ''))) {
            $blockers[] = 'Nedostaje Wolt ID prodajnog mjesta.';
        }
        if (! $this->credentialsConfigured()) {
            $blockers[] = 'Nedostaju pristupni podaci za odabrani način prijave.';
        }
        if ($options['auth_type'] === 'bearer' && $this->credentialsConfigured()) {
            $expiresAt = $this->bearerExpiresAt();
            if ($expiresAt === null) {
                $blockers[] = 'Nedostaje rok važenja OAuth pristupnog tokena.';
            } elseif ($expiresAt <= now()->timestamp + 30) {
                $blockers[] = 'OAuth pristupni token je istekao ili upravo istječe. Treba ga obnoviti.';
            }
        }
        if ($options['scope'] === 'selected' && $options['product_ids'] === []) {
            $blockers[] = 'Nisu odabrani proizvodi za postojeći Wolt asortiman.';
        }

        return $blockers;
    }

    private function bearerExpiresAt(): ?int
    {
        $expiry = $this->options()['access_token_expires_at'] ?? null;
        $manualExpiry = null;
        if ($expiry) {
            try {
                $manualExpiry = \Carbon\CarbonImmutable::parse($expiry)->timestamp;
            } catch (\Throwable) {
                return null;
            }
        }
        $parts = explode('.', (string) ($this->credentials()['access_token'] ?? ''));
        if (count($parts) === 3) {
            $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
            if (is_array($claims) && is_numeric($claims['exp'] ?? null)) {
                return $manualExpiry === null ? (int) $claims['exp'] : min($manualExpiry, (int) $claims['exp']);
            }
        }

        return $manualExpiry;
    }

    public function endpoint(string $operation): string
    {
        abort_unless(in_array($operation, self::OPERATIONS, true), 404);
        $options = $this->options();
        $host = $options['environment'] === 'production'
            ? 'https://pos-integration-service.wolt.com'
            : 'https://pos-integration-service.development.dev.woltapi.com';

        return $host.'/venues/'.rawurlencode((string) $options[$operation.'_venue_id']).'/items'.($operation === 'inventory' ? '/inventory' : '');
    }
}
