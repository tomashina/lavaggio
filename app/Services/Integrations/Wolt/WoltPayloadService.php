<?php

namespace App\Services\Integrations\Wolt;

use App\Models\Catalog\Product\Product;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Pricing\TaxPricingService;

class WoltPayloadService
{
    public function __construct(
        private readonly WoltSettingsService $settings,
        private readonly ProductPricePresentationService $pricing,
        private readonly TaxPricingService $taxPricing,
    ) {}

    /** Builds a reviewable PATCH body without calling Wolt or modifying products. */
    public function preview(string $operation): array
    {
        abort_unless(in_array($operation, WoltSettingsService::OPERATIONS, true), 404);
        $options = $this->settings->options();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $options['product_ids']))));
        $query = Product::query();
        if ($operation === 'catalog' && $options['price_basis'] === 'current_gross') {
            $query->with(['taxRate', 'categories', 'manufacturer']);
        } elseif ($operation === 'catalog' && $options['include_vat']) {
            $query->with('taxRate');
        }
        if ($options['scope'] === 'source_imported') {
            $query->where(function ($source): void {
                $source->whereNotNull('payload->opencart->product_id')
                    ->orWhere(fn ($legacy) => $legacy->where('payload->source->system', 'opencart')->whereNotNull('payload->source->product_id'));
            });
        } else {
            $query->whereIn('id', $ids);
        }
        $products = $query->orderBy('id')->get();
        $data = [];
        $errors = [];
        $seen = [];
        $deduplicated = 0;
        $groups = [];
        $conflictingSkus = [];

        if ($options['scope'] === 'selected' && $ids === []) {
            $errors[] = 'Nisu odabrani proizvodi za Wolt.';
        }
        if ($options['scope'] === 'selected' && $products->count() !== count($ids)) {
            $errors[] = 'Neki odabrani proizvodi više ne postoje.';
        }
        foreach ($products as $product) {
            $sku = $this->sku($product, (string) $options['sku_field']);
            if ($sku === '' || mb_strlen($sku) > 255 || preg_match('/[\x00-\x1F\x7F]/', $sku)) {
                $errors[] = 'Proizvod #'.$product->id.' nema valjanu Wolt šifru.';

                continue;
            }
            if ($operation === 'inventory') {
                $row = ['sku' => $sku, 'inventory' => $options['availability_basis'] === 'stock_quantity' || $product->is_active
                    ? max(0, (int) $product->stock_qty) : 0];
            } else {
                $gross = $options['price_basis'] === 'stored_base'
                    ? $product->base_price : ($this->pricing->forProduct($product, user: null)['current_gross'] ?? null);
                if (! is_numeric($gross) || ! is_finite((float) $gross) || (float) $gross < 0 || (float) $gross > PHP_INT_MAX / 100) {
                    $errors[] = 'Proizvod #'.$product->id.' nema valjanu javnu cijenu.';

                    continue;
                }
                $rate = $options['include_vat'] ? $this->taxPricing->resolveRateForProduct($product) : null;
                if ($options['include_vat'] && $rate && (string) $rate->rate_type !== 'percent') {
                    $errors[] = 'Proizvod #'.$product->id.' nema postotnu poreznu stopu za Wolt.';

                    continue;
                }
                $row = [
                    'sku' => $sku,
                    'price' => (int) round((float) $gross * 100),
                    'enabled' => $options['availability_basis'] === 'stock_quantity'
                        ? (int) $product->stock_qty > 0 : (bool) $product->is_active,
                ];
                if ($options['include_vat']) {
                    $row['vat_percentage'] = $rate ? (float) $rate->rate : 0.0;
                }
            }
            if (isset($seen[$sku])) {
                if ($seen[$sku] === $row) {
                    $deduplicated++;
                } else {
                    $conflictingSkus[$sku] = true;
                }
            } else {
                $seen[$sku] = $row;
                $data[] = $row;
            }
            $groups[$sku][] = [
                'product_id' => (int) $product->id,
                'source_product_id' => data_get($product->payload, 'opencart.product_id') ?? data_get($product->payload, 'source.product_id'),
                'code' => $product->code, 'base_price' => (float) $product->base_price,
                'stock_qty' => (int) $product->stock_qty, 'is_active' => (bool) $product->is_active,
                'edit_url' => route('admin.products.edit', $product),
            ];
        }
        $conflicts = [];
        $warnings = [];
        if ($conflictingSkus !== []) {
            $data = array_values(array_filter($data, fn (array $row): bool => ! isset($conflictingSkus[(string) $row['sku']])));
            foreach (array_keys($conflictingSkus) as $sku) {
                $conflicts[] = ['sku' => (string) $sku, 'products' => $groups[$sku]];
                $warnings[] = 'Šifra '.$sku.' preskočena je zbog različitih '.($operation === 'catalog' ? 'cijena ili vidljivosti' : 'zaliha').'. Provjerite zapise proizvoda u Wolt postavkama.';
            }
        }

        return [
            'operation' => $operation,
            'selected_count' => $options['scope'] === 'source_imported' ? $products->count() : count($ids),
            'item_count' => count($data),
            'deduplicated_count' => $deduplicated,
            'skipped_conflicts' => count($conflicts),
            'conflicts' => $conflicts,
            'warnings' => $warnings,
            'payload' => ['data' => $data],
            'errors' => $errors,
            'blockers' => $this->settings->sendBlockers($operation),
        ];
    }

    private function sku(Product $product, string $field): string
    {
        return trim((string) match ($field) {
            'code' => $product->code,
            'barcode' => $product->barcode,
            'source_product_id' => data_get($product->payload, 'opencart.product_id')
                ?? data_get($product->payload, 'source.product_id'),
            'opencart_model' => data_get($product->payload, 'opencart.model'),
            'explicit' => data_get($product->payload, 'integrations.wolt.sku'),
            default => $product->sku,
        });
    }
}
