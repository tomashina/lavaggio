<?php

namespace App\Http\Controllers\Feed;

use App\Models\Catalog\Product\Product;
use App\Services\Pricing\ProductPricePresentationService;
use App\Support\Lavaggio\ProductBarcode;
use Illuminate\Http\Response;
use XMLWriter;

class LavaggioStockFeedController
{
    public function __invoke(ProductPricePresentationService $prices): Response
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('artikli');
        $xml->writeAttribute('valuta', 'EUR');

        $products = Product::query()->visibleOnStorefront()->with([
            'translations', 'taxRate', 'categories:id',
            'optionValues.optionValue.translations', 'optionValues.optionValue.option',
            'optionValues.parentOptionValue.translations', 'optionValues.parentOptionValue.option',
        ])->lazyById(200);
        foreach ($products as $product) {
            $name = (string) ($product->translations->firstWhere('locale', 'hr')?->name
                ?? $product->translations->first()?->name ?? $product->code);
            $variants = $product->visibleOptionRows();
            foreach ($variants->isEmpty() ? [null] : $variants as $variant) {
                $labels = [$name];
                foreach ([$variant?->parentOptionValue, $variant?->optionValue] as $value) {
                    if ($value) {
                        $labels[] = $value->translations->firstWhere('locale', 'hr')?->name ?? $value->code;
                    }
                }
                $price = $prices->forStoredBase($product, (float) ($variant?->price_override ?? $product->base_price));
                $weight = data_get($variant?->payload, 'weight_kg') ?? $product->weight_kg;
                $xml->startElement('artikl');
                foreach ([
                    'sku' => $variant ? $variant->sku : $product->sku,
                    'naziv' => implode(' – ', array_filter($labels)),
                    'ean' => ProductBarcode::ean($product, $variant),
                    'kolicina' => max(0, (int) ($variant ? $variant->stock_qty : $product->stock_qty)),
                    'tezina_kg' => (float) $weight > 0 ? number_format((float) $weight, 3, '.', '') : '',
                    'mpc' => $price['current_gross'] === null ? '' : number_format($price['current_gross'], 2, '.', ''),
                ] as $field => $value) {
                    $xml->writeElement($field, preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value));
                }
                $xml->endElement();
            }
        }
        $xml->endElement();
        $xml->endDocument();

        return response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
