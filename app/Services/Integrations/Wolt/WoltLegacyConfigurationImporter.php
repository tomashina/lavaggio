<?php

namespace App\Services\Integrations\Wolt;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WoltLegacyConfigurationImporter
{
    public function __construct(
        private readonly WoltSettingsService $settings,
        private readonly SystemSettingsService $systemSettings,
    ) {}

    /** Reads PHP as text. The original cron scripts are never included or executed. */
    public function import(string $directory, bool $force = false): array
    {
        if (! $force && $this->systemSettings->get('watchline_wolt_source_import')) {
            return ['status' => 'unchanged', 'imported' => false];
        }
        $inventorySource = $this->source($directory.'/index.php');
        $catalogSource = $this->source($directory.'/inventory.php');
        $username = $this->assignment($inventorySource, 'username');
        $password = $this->assignment($inventorySource, 'password');
        if ($username !== $this->assignment($catalogSource, 'woltUsername')
            || $password !== $this->assignment($catalogSource, 'woltPassword')) {
            throw new RuntimeException('Izvorne Wolt skripte koriste različite pristupne podatke. Potrebna je zasebna provjera.');
        }
        $inventoryVenue = $this->endpointVenue($inventorySource, '/items/inventory');
        $catalogVenue = $this->endpointVenue($catalogSource, '/items');
        if (! preg_match('/model\s+(?:AS\s+)?sku/i', $inventorySource)
            || ! preg_match('/model\s+(?:AS\s+)?sku/i', $catalogSource)
            || ! str_contains($inventorySource, 'base64_encode')
            || ! str_contains($catalogSource, 'base64_encode')) {
            throw new RuntimeException('Izvorne Wolt skripte nemaju očekivani ugovor povezivanja.');
        }

        DB::transaction(function () use ($username, $password, $catalogVenue, $inventoryVenue, $inventorySource, $catalogSource): void {
            $this->settings->save(array_replace($this->settings->options(), [
                'environment' => 'production', 'auth_type' => 'legacy_basic',
                'catalog_venue_id' => $catalogVenue, 'inventory_venue_id' => $inventoryVenue,
                'scope' => 'source_imported', 'sku_field' => 'opencart_model', 'product_ids' => [],
                'price_basis' => 'stored_base', 'availability_basis' => 'stock_quantity', 'include_vat' => false,
                'mapping_confirmed' => false, 'auth_confirmed' => false, 'send_enabled' => false,
            ]), ['username' => $username, 'password' => $password]);
            $this->systemSettings->put('watchline_wolt_source_import', [
                'version' => 1, 'imported_at' => now()->toIso8601String(),
                'catalog_sha256' => hash('sha256', $catalogSource),
                'inventory_sha256' => hash('sha256', $inventorySource),
                'separate_venues' => $catalogVenue !== $inventoryVenue,
                'catalog_legacy_path' => 'inventory.php', 'inventory_legacy_path' => 'index.php',
                'catalog_schedule' => '12,32,52 * * * *', 'inventory_schedule' => '15,35,55 * * * *',
            ]);
        });

        return ['status' => 'imported', 'imported' => true, 'separate_venues' => $catalogVenue !== $inventoryVenue, 'send_enabled' => false];
    }

    private function source(string $path): string
    {
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 100000) {
            throw new RuntimeException('Nije moguće pročitati privatnu izvornu Wolt skriptu.');
        }

        return (string) file_get_contents($path);
    }

    private function assignment(#[\SensitiveParameter] string $source, string $variable): string
    {
        $tokens = token_get_all($source);
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$'.$variable) {
                continue;
            }
            $next = $index + 1;
            while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            if (($tokens[$next] ?? null) !== '=') {
                continue;
            }
            $next++;
            while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                $next++;
            }
            if (is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_CONSTANT_ENCAPSED_STRING) {
                return $this->literal($tokens[$next][1]);
            }
        }

        throw new RuntimeException('U izvornoj Wolt skripti nedostaje očekivana pristupna postavka.');
    }

    private function endpointVenue(#[\SensitiveParameter] string $source, string $suffix): string
    {
        if (! preg_match('/CURLOPT_URL\s*(?:,|=>)\s*([\'\"])(https:\/\/pos-integration-service\.wolt\.com\/venues\/([^\/]+)'.preg_quote($suffix, '/').')\1/', $source, $matches)) {
            throw new RuntimeException('Izvorna Wolt skripta nema očekivanu stvarnu adresu API-ja.');
        }
        $venue = $matches[3];
        if ($matches[1] === '"' && preg_match('/\A\$([a-zA-Z_][a-zA-Z0-9_]*)\z/', $venue, $variable)) {
            $venue = $this->assignment($source, $variable[1]);
        }
        if (! preg_match('/\A[a-zA-Z0-9_-]+\z/', $venue)) {
            throw new RuntimeException('Izvorna Wolt adresa nema valjan ID prodajnog mjesta.');
        }

        return $venue;
    }

    private function literal(#[\SensitiveParameter] string $literal): string
    {
        $value = substr($literal, 1, -1);

        return str_starts_with($literal, "'")
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $value)
            : stripcslashes($value);
    }
}
