<?php

namespace App\Support\Lavaggio;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Services\Pricing\ProductPricePresentationService;
use RuntimeException;

class RetailPriceLists
{
    public const GOODS = [
        'naziv' => 'Naziv robe', 'sifra' => 'Šifra robe', 'marka' => 'Marka robe', 'neto_kolicina' => 'Neto količina',
        'jedinica_mjere' => 'Jedinica mjere', 'maloprodajna_cijena' => 'Maloprodajna cijena',
        'poseban_oblik_prodaje' => 'Poseban oblik prodaje', 'cijena_za_jedinicu_mjere' => 'Cijena za jedinicu mjere',
        'barkod' => 'Barkod', 'ean_barkod' => 'EAN barkod', 'bazna_cijena' => 'Bazna cijena',
    ];

    public const SERVICES = [
        'naziv' => 'Naziv usluge', 'maloprodajna_cijena' => 'Maloprodajna cijena',
        'poseban_oblik_prodaje' => 'Poseban oblik prodaje', 'bazna_cijena' => 'Bazna cijena',
    ];

    public function goods(): array
    {
        $prices = app(ProductPricePresentationService::class);
        $rows = [];
        $products = Product::where('is_active', true)->with(['translations', 'manufacturer.translations', 'taxRate',
            'optionValues' => fn ($q) => $q->where('is_active', true)->with(['optionValue.translations', 'optionValue.option', 'parentOptionValue.option'])])->orderBy('sku')->orderBy('id')->get();
        foreach ($products as $product) {
            $name = $product->translations->firstWhere('locale', 'hr')?->name;
            if (! $name) {
                throw new RuntimeException('Nedostaje hrvatski naziv artikla '.$product->code);
            }
            $brand = $product->manufacturer?->translations->firstWhere('locale', 'hr')?->name ?? '';
            $variants = $product->optionValues->filter(fn ($option) => $option->isVisibleOnStorefront());
            foreach ($variants->isEmpty() ? [null] : $variants as $variant) {
                $quote = $prices->forStoredBase($product, (float) ($variant?->price_override ?? $product->base_price));
                if (! is_numeric($quote['current_gross'])) {
                    throw new RuntimeException('Nedostaje javna maloprodajna cijena '.$product->code);
                }
                $gross = round($quote['current_gross'], 2);
                $unit = ['kg' => 'kg', 'l' => 'l', 'pcs' => 'kom'][$product->content_unit] ?? '';
                $quantity = (float) $product->content_quantity;
                $barcode = trim((string) ($variant ? data_get($variant->payload, 'barcode') : $product->barcode));
                $baseline = $variant ? null : RetailBasePrice::forProduct($product);
                $variantName = $variant?->optionValue?->translations->firstWhere('locale', 'hr')?->name;
                $rows[] = [$name.($variant ? ' – '.($variantName ?: $variant->sku ?: $variant->id) : ''), (string) ($variant?->sku ?: $product->sku ?: $product->code),
                    $brand, $quantity > 0 && $unit !== '' ? rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.') : '',
                    $unit, self::amount($gross), $quote['has_promotional_discount'] ? 'DA' : 'NE',
                    $quantity > 0 && $unit !== '' ? self::amount($gross / $quantity) : '', $barcode, ProductBarcode::ean($product, $variant),
                    $baseline ? self::amount($baseline['price']) : ''];
            }
        }

        return $rows;
    }

    public function services(): array
    {
        $rows = [];
        $presenter = app(ServicePricingAnchorPrices::class);
        foreach (['cjenik-usluzno-pranje', 'cjenik-samoposluzna-praonica'] as $slug) {
            $page = InfoPage::where('is_active', true)->whereHas('translations', fn ($q) => $q->where('locale', 'hr')->where('slug', $slug))
                ->with('translations')->first();
            if (! $page) {
                continue;
            }
            $html = (string) $page->translations->firstWhere('locale', 'hr')->body_html;
            $services = $presenter->present($html, $slug === 'cjenik-samoposluzna-praonica')['rows'];
            if (! $services) {
                throw new RuntimeException('Nije moguće očitati postojeći cjenik '.$slug);
            }
            foreach ($services as $service) {
                $name = $service['name'];
                $price = $this->serviceAmount($service['price']);
                $baseline = $this->serviceAmount($service['base'] ?? '');
                if (preg_match('/(?:€|EUR)\s*(\/\s*[^(),]+|po\s+\p{L}+|kg\s*\/\s*dan)/u', $service['price'], $unit)) {
                    $name .= ' ('.trim($unit[1]).')';
                }
                $rows[] = [$name, $price, $service['sale'] ? 'DA' : 'NE', $baseline];
            }
        }

        return $rows;
    }

    private function serviceAmount(string $price): string
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)\s*(€|EUR|%)/u', $price, $match)) {
            $amount = self::amount((float) str_replace(',', '.', $match[1]));

            return $match[2] === '%' ? '+'.$amount.' %' : $amount;
        }

        return $price;
    }

    private static function amount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
