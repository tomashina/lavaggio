<?php

namespace App\Http\Controllers\Feed;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Catalog\Product\ProductTranslation;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Lavaggio\ProductBarcode;
use App\Support\Media\LegacyCatalogImage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use XMLWriter;

class GoogleMerchantController
{
    public function __invoke(SystemSettingsService $settings, ProductPricePresentationService $prices): StreamedResponse
    {
        abort_unless($settings->get('lavaggio_merchant_feed_enabled', false), 404);
        abort_unless(app(B2BAccessService::class)->canViewPrices(null), 403);
        $includeUnavailable = (bool) $settings->get('lavaggio_merchant_include_unavailable', true);
        $store = $settings->get('store_brand_name', 'Lavaggio') ?: 'Lavaggio';
        $origin = rtrim((string) config('app.url'), '/');

        return response()->stream(function () use ($prices, $includeUnavailable, $store, $origin): void {
            $xml = new XMLWriter;
            $xml->openUri('php://output');
            $xml->startDocument('1.0', 'UTF-8');
            $xml->startElement('rss');
            $xml->writeAttribute('version', '2.0');
            $xml->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
            $xml->startElement('channel');
            $xml->writeElement('title', $this->plainText($store, 150));
            $xml->writeElement('link', $origin.'/');
            $xml->writeElement('description', $this->plainText($store.' – katalog proizvoda', 5000));

            $products = Product::query()->visibleOnStorefront()->with([
                'translations', 'taxRate', 'manufacturer.translations', 'categories.translations', 'media',
                'optionValues.optionValue.option', 'optionValues.optionValue.translations',
                'optionValues.parentOptionValue.option', 'optionValues.parentOptionValue.translations',
            ])->lazyById(100);
            foreach ($products as $product) {
                $translation = $product->translations->firstWhere('locale', 'hr');
                $image = LegacyCatalogImage::first($product);
                if (! filled($translation?->slug) || ! filled($translation->name) || ! $image) {
                    continue;
                }
                $category = $product->categories->sortByDesc('pivot.is_primary')->first();
                $base = [
                    'description' => $this->plainText($translation->description ?: $translation->excerpt ?: $translation->name, 5000),
                    'image_link' => str_starts_with($image, '/') ? $origin.$image : $image,
                    'brand' => $product->manufacturer?->translations->firstWhere('locale', 'hr')?->name,
                    'product_type' => $category?->translations->firstWhere('locale', 'hr')?->name,
                    'condition' => 'new',
                ];
                $variants = $product->visibleOptionRows();
                foreach ($variants->isEmpty() ? [null] : $variants as $variant) {
                    $available = $product->availableStockQuantity($variant) > 0;
                    if (! $available && ! $includeUnavailable) {
                        continue;
                    }
                    $price = $prices->forStoredBase($product, (float) ($variant?->price_override ?? $product->base_price), withReferencePrice: false);
                    if ($price['current_gross'] <= 0) {
                        continue;
                    }
                    $fields = $this->offer($product, $variant, $translation, $origin) + $base;
                    $fields['availability'] = $available ? 'in_stock' : 'out_of_stock';
                    $current = round((float) $price['current_gross'], 2);
                    $regular = round((float) ($price['old_gross'] ?? $current), 2);
                    $fields['price'] = number_format(max($current, $regular), 2, '.', '').' EUR';
                    if ($regular > $current) {
                        $fields['sale_price'] = number_format($current, 2, '.', '').' EUR';
                    }

                    $xml->startElement('item');
                    foreach ($fields as $name => $value) {
                        if ($value !== null && $value !== '') {
                            $xml->writeElement('g:'.$name, preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '');
                        }
                    }
                    $xml->endElement();
                }
            }
            $xml->endElement();
            $xml->endElement();
            $xml->endDocument();
            $xml->flush();
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    private function offer(Product $product, ?ProductOptionValue $variant, ProductTranslation $translation, string $origin): array
    {
        $params = ['slug' => $translation->slug];
        $labels = [$translation->name];
        $productId = (string) ($product->sku ?: $product->code ?: 'p-'.$product->id);
        $fields = ['id' => $variant ? ($variant->sku ?: 'v-'.$variant->id) : $productId];
        if ($variant) {
            $fields['item_group_id'] = $productId;
            $params['product_option_value_id'] = $variant->id;
            foreach ([$variant->parentOptionValue, $variant->optionValue] as $value) {
                if ($value) {
                    $label = $value->translations->firstWhere('locale', 'hr')?->name ?? $value->code;
                    $labels[] = $label;
                    $attribute = match (Str::lower((string) $value->option?->code)) {
                        'boja', 'color', 'colour' => 'color',
                        'velicina', 'size' => 'size',
                        'materijal', 'material' => 'material',
                        default => null,
                    };
                    if ($attribute) {
                        $fields[$attribute] = $label;
                    }
                }
            }
        }
        $fields['title'] = $this->plainText(implode(' – ', $labels), 150);
        $fields['link'] = $origin.route('products.show', $params, false);
        $fields['gtin'] = ProductBarcode::gtin($product, $variant);
        $fields['mpn'] = data_get($variant ? $variant->payload : $product->payload, 'mpn');
        $unit = ['l' => 'l', 'kg' => 'kg', 'pcs' => 'ct'][$product->content_unit] ?? null;
        if (! $variant && $unit && $product->content_quantity > 0) {
            $fields['unit_pricing_measure'] = (float) $product->content_quantity.$unit;
            $fields['unit_pricing_base_measure'] = '1'.$unit;
        }

        return $fields;
    }

    private function plainText(string $value, int $limit): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $value) ?? '';
        $value = strip_tags(preg_replace('#<(?:br\b[^>]*|/(?:p|div|li|h[1-6]))>#i', ' ', $value) ?? '');
        $value = preg_replace('/[\p{C}\s]+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, $limit);
    }
}
