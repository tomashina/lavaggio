<?php

namespace App\Services\Integrations\Fira;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Services\Front\GuestStorefrontCache;
use App\Services\Pricing\TaxPricingService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Read-only in FIRA. The documented product API has prices, but no warehouse quantities. */
class FiraPriceSyncService
{
    public function __construct(
        private readonly FiraSettingsService $settings,
        private readonly TaxPricingService $taxPricing,
        private readonly SystemSettingsService $systemSettings,
    ) {}

    public function sync(bool $apply = false): array
    {
        if (config('storefront.home') !== 'lavaggio' || $this->settings->secret() === '') {
            $this->fail('Za dohvat cijena potreban je Lavaggio i spremljen FIRA API ključ.');
        }

        $lock = Cache::lock('lavaggio:fira-price-sync', 3600);
        if (! $lock->get()) {
            $this->fail('Sinkronizacija cijena već je u tijeku.');
        }

        try {
            // Fetch every page before making any changes. A failed or incomplete read changes nothing.
            $rows = $this->fetchProducts();
            $result = DB::transaction(function () use ($rows, $apply): array {
                $targets = [];
                foreach (Product::query()->with('taxRate')->lockForUpdate()->get() as $product) {
                    $sku = trim((string) $product->sku) ?: trim((string) $product->code);
                    if ($sku !== '') {
                        $targets[$sku][] = [$product, $product, 'base_price'];
                    }
                }
                foreach (ProductOptionValue::query()->with('product.taxRate')->where('mode', '!=', 'filter')->lockForUpdate()->get() as $variant) {
                    $sku = trim((string) $variant->sku);
                    if ($sku !== '' && $variant->product) {
                        $targets[$sku][] = [$variant, $variant->product, 'price_override'];
                    }
                }

                $counts = array_count_values(array_map(fn ($row) => trim((string) ($row['productNumber'] ?? '')), $rows));
                $summary = ['received' => count($rows), 'matched' => 0, 'changed' => 0, 'unchanged' => 0,
                    'unmatched' => 0, 'ambiguous' => 0, 'invalid' => 0, 'applied' => $apply,
                    'stock_synced' => false];
                $priceField = $this->taxPricing->pricesIncludeTax() ? 'grossPrice' : 'netPrice';

                foreach ($rows as $row) {
                    $sku = trim((string) ($row['productNumber'] ?? ''));
                    if ($sku === '' || ! isset($targets[$sku])) {
                        $summary['unmatched']++;

                        continue;
                    }
                    if ($counts[$sku] !== 1 || count($targets[$sku]) !== 1) {
                        $summary['ambiguous']++;

                        continue;
                    }
                    [$target, $product, $field] = $targets[$sku][0];
                    $price = $row[$priceField] ?? null;
                    $tax = $this->taxPricing->resolveRateForProduct($product);
                    if (($row['status'] ?? null) !== 'ACTIVE' || ($row['currency'] ?? null) !== 'EUR'
                        || ! is_numeric($price) || ! is_finite((float) $price) || $price < 0 || $price > 9999999999.99
                        || ($tax && $tax->rate_type !== 'percent')
                        || ! $this->compatibleTax($row, (float) ($tax?->rate ?? 0))) {
                        $summary['invalid']++;

                        continue;
                    }
                    $summary['matched']++;
                    $newPrice = round((float) $price, 2);
                    if ($target->$field !== null && abs((float) $target->$field - $newPrice) < 0.00001) {
                        $summary['unchanged']++;

                        continue;
                    }
                    $summary['changed']++;
                    if ($apply) {
                        // Model observers retain the existing price-history and cache behavior.
                        // Stock, descriptions, promotions and the fixed listing price are preserved.
                        $target->update([$field => $newPrice]);
                    }
                }

                return $summary;
            });

            if ($apply) {
                $result['completed_at'] = now()->toIso8601String();
                $this->systemSettings->put('lavaggio_fira_price_sync', $result);
                app(GuestStorefrontCache::class)->invalidate();
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function fetchProducts(): array
    {
        $rows = [];
        $totalItems = null;
        $totalPages = null;
        for ($page = 0; $page < 100; $page++) {
            try {
                $response = Http::acceptJson()->withHeaders(['FIRA-Api-Key' => $this->settings->secret()])
                    ->connectTimeout(5)->timeout(20)->withoutRedirecting()
                    ->get(FiraSettingsService::PRODUCTS_ENDPOINT, ['status' => 'ACTIVE', 'page' => $page, 'size' => 100]);
            } catch (\Throwable) {
                $this->fail('FIRA nije dostupna. Cijene nisu izmijenjene.');
            }
            if (! $response->successful()) {
                $this->fail(match ($response->status()) {
                    401 => 'FIRA je odbila API ključ.',
                    403 => 'U FIRA integraciji treba uključiti „Dozvoli dohvat podataka”.',
                    429 => 'FIRA ograničenje broja zahtjeva. Pokušajte kasnije.',
                    default => 'FIRA nije potvrdila dohvat cijena (HTTP '.$response->status().').',
                });
            }
            $data = $response->json();
            if (! is_array($data) || ! is_array($data['items'] ?? null) || ! array_is_list($data['items'])
                || ! is_int($data['page'] ?? null) || $data['page'] !== $page
                || ! is_int($data['totalPages'] ?? null) || $data['totalPages'] < 0 || $data['totalPages'] > 100
                || ! is_int($data['totalItems'] ?? null) || $data['totalItems'] < 0
                || count($data['items']) > 100
                || ($totalPages !== null && $totalPages !== $data['totalPages'])
                || ($totalItems !== null && $totalItems !== $data['totalItems'])) {
                $this->fail('FIRA je vratila nepotpun ili promijenjen popis artikala. Cijene nisu izmijenjene.');
            }
            $totalItems = $data['totalItems'];
            $totalPages = $data['totalPages'];
            foreach ($data['items'] as $row) {
                if (! is_array($row) || ! is_scalar($row['id'] ?? null)
                    || isset($rows[(string) $row['id']])
                    || (isset($row['productNumber']) && ! is_string($row['productNumber']))) {
                    $this->fail('FIRA je vratila neispravan ili ponovljen artikl. Cijene nisu izmijenjene.');
                }
                $rows[(string) $row['id']] = $row;
            }
            if ($page + 1 >= $totalPages) {
                if (count($rows) !== $totalItems) {
                    $this->fail('FIRA nije vratila sve artikle. Cijene nisu izmijenjene.');
                }

                return array_values($rows);
            }
        }

        $this->fail('Prevelik FIRA katalog za jedan dohvat. Cijene nisu izmijenjene.');
    }

    private function compatibleTax(array $row, float $rate): bool
    {
        foreach (['netPrice', 'grossPrice', 'taxPercentage'] as $field) {
            if (! is_numeric($row[$field] ?? null) || ! is_finite((float) $row[$field])
                || $row[$field] < 0 || $row[$field] > 9999999999.99) {
                return false;
            }
        }
        $net = (float) $row['netPrice'];
        $gross = (float) $row['grossPrice'];
        $reportedTax = (float) $row['taxPercentage'];
        // Allow a cent for separately rounded net/gross prices, but reject another VAT rate.
        if (abs(round($net * (1 + $rate / 100), 2) - round($gross, 2)) > 0.010001) {
            return false;
        }

        // Live FIRA data (2026-11-16) mixes percentages and tax amounts in this field.
        // Accept the latter only when both the tax amount and net/gross math agree.
        return abs($reportedTax - $rate) < 0.0001
            || abs($reportedTax - ($gross - $net)) < 0.010001;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['fira_sync' => $message]);
    }
}
