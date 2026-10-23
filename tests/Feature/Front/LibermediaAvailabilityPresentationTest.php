<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LibermediaAvailabilityPresentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Liber Media', 'store_pricing_b2c_enabled' => true]);
    }

    #[DataProvider('stockStates')]
    public function test_every_card_layout_and_product_detail_show_one_truthful_status(int $local, int $supplier, ?int $variant, bool $available): void
    {
        $product = $this->product($local, $supplier, $variant);
        view()->share('errors', new ViewErrorBag);

        foreach ([[false, false], [true, false], [true, true]] as [$flat, $lined]) {
            $html = Blade::render('<x-front.desktop.product-card :product="$product" :flat="$flat" :lined="$lined" />', compact('product', 'flat', 'lined'));
            $this->assertAvailability($html, $available);
        }

        foreach (['Desktop', 'iPhone Mobile'] as $device) {
            $html = $this->withHeader('User-Agent', $device)->get(route('products.show', ['slug' => 'availability-product']))->assertOk()->getContent();
            $this->assertAvailability($html, $available);
        }

        $this->assertSame($local, $product->fresh()->stock_qty);
        $this->assertSame($supplier, $product->fresh()->supplier_stock_qty);
        if ($variant !== null) {
            $this->assertSame($variant, $product->fresh()->optionValues->sole()->stock_qty);
        }
    }

    public static function stockStates(): array
    {
        return [
            'local stock' => [37, 0, null, true],
            'supplier stock' => [0, 81, null, true],
            'out of stock' => [0, 0, null, false],
            'variant stock overrides empty product' => [0, 0, 12, true],
            'empty variant overrides aggregate stock' => [37, 81, 0, false],
        ];
    }

    public function test_other_stores_keep_the_two_stock_buckets_and_quantities(): void
    {
        config(['storefront.brand' => 'auto']);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $product = $this->product(37, 81);
        view()->share('errors', new ViewErrorBag);

        $card = Blade::render('<x-front.desktop.product-card :product="$product" />', compact('product'));
        $detail = $this->get(route('products.show', ['slug' => 'availability-product']))->assertOk()->getContent();
        foreach ([$card, $detail] as $html) {
            $xpath = $this->xpath($html);
            $this->assertSame('37', $xpath->query('//*[@data-product-local-stock]')->item(0)->textContent);
            $this->assertSame('81', $xpath->query('//*[@data-product-supplier-stock]')->item(0)->textContent);
            $this->assertStringContainsString('Dostupnost 48 sati:', $html);
            $this->assertStringContainsString('Dostupnost 14 dana:', $html);
            $this->assertSame(0, $xpath->query('//*[@data-product-availability-state]')->count());
        }
    }

    private function assertAvailability(string $html, bool $available): void
    {
        $xpath = $this->xpath($html);
        $statuses = $xpath->query('//*[@data-product-availability-state]');
        $this->assertSame(1, $statuses->count());
        $this->assertSame($available ? 'available' : 'unavailable', $statuses->item(0)->getAttribute('data-product-availability-state'));
        $this->assertSame('● '.($available ? 'Dostupno' : 'Trenutno nedostupno'), preg_replace('/\s+/u', ' ', trim($statuses->item(0)->textContent)));
        $this->assertStringContainsString($available ? 'text-emerald-700' : 'text-slate-500', $statuses->item(0)->getAttribute('class'));
        $this->assertSame(0, $xpath->query('//*[@data-product-local-stock or @data-product-supplier-stock]')->count());
        $this->assertStringNotContainsString('Dostupnost 48 sati:', $html);
        $this->assertStringNotContainsString('Dostupnost 14 dana:', $html);
    }

    private function product(int $local, int $supplier, ?int $variant = null): Product
    {
        $product = Product::query()->create([
            'code' => 'AVAILABILITY', 'is_active' => true, 'base_price' => 20,
            'stock_qty' => $local, 'supplier_stock_qty' => $supplier,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Availability product', 'slug' => 'availability-product']);
        if ($variant !== null) {
            $option = Option::query()->create(['code' => 'size', 'type' => 'radio', 'is_active' => true]);
            $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'small', 'is_active' => true]);
            $product->optionValues()->create([
                'option_value_id' => $value->id, 'mode' => 'variant', 'stock_qty' => $variant,
                'is_active' => true, 'combination_hash' => hash('sha256', 'availability-small'),
            ]);
        }

        return $product;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
