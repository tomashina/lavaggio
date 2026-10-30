<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use App\Support\LavaggioProductPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.name' => 'Lavaggio', 'app.locale' => 'hr',
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia', 'storefront_cache.enabled' => false,
            'commerce.b2b_only' => false, 'commerce.b2c_only' => true,
        ]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Lavaggio', 'store_pricing_prices_include_tax' => true,
            'lavaggio_free_shipping_threshold' => 50,
        ]);
    }

    public function test_detail_shows_verified_commerce_data_and_five_editable_information_panels(): void
    {
        $product = $this->product();
        $action = $this->action($product);

        $response = $this->get(route('products.show', 'profesionalni-deterdzent'))->assertOk()
            ->assertSee('Profesionalni proizvod')->assertSee('16,00 €')
            ->assertSee('2,7 l (5,93 € za 1 l)')->assertSee('Cijena na')
            ->assertSee('10.09.2026.')->assertSee('NC 30 dana: 20,00 €')->assertSee('-20%')
            ->assertSee('data-ends-at="'.$action->ends_at->toIso8601String().'"', false)
            ->assertSee('data-countdown-part="days"', false)->assertSee('DANA')
            ->assertSee('data-countdown-part="hours"', false)->assertSee('SATA')
            ->assertSee('data-countdown-part="minutes"', false)->assertSee('MINUTA')
            ->assertSee('data-countdown-part="seconds"', false)->assertSee('SEKUNDI')->assertSee('ZADNJI KOMADI')
            ->assertDontSee('lavaggio-stock-quantity', false)->assertDontSee('2 kom. na stanju')
            ->assertSee('Dostava u 2–3 radna dana')
            ->assertSee('Besplatna dostava iznad 50,00 €')
            ->assertSee('Jedinična cijena s uključenim PDV-om.')
            ->assertSee('SKU')->assertSee('PRO-LAUNDRY-1')->assertSee('EAN')->assertSee('4000000000018')
            ->assertSee('Sastojci')->assertSee('Površinski aktivne tvari.')
            ->assertSee('Napomene o opasnosti')->assertSee('Čuvati izvan dohvata djece.')
            ->assertSee('Proizvođač / uvoznik')->assertSee('Primjer proizvođača, Zagreb.')
            ->assertSee('Zemlja podrijetla')->assertSee('Njemačka')->assertSee('Još nema ocjena');

        $this->assertSame(5, substr_count($response->getContent(), 'data-lavaggio-legal-panel='));

        $this->get(route('shop.index'))->assertOk()
            ->assertSee('lavaggio-card-pro')->assertSee('2,7 l (5,93 € za 1 l)')
            ->assertSee('ZADNJI KOMADI');
    }

    public function test_expired_promotion_cannot_render_discount_price_or_countdown(): void
    {
        $product = $this->product();
        $this->action($product)->update(['ends_at' => now()->subSecond()]);

        $this->get(route('products.show', 'profesionalni-deterdzent'))->assertOk()
            ->assertSee('20,00 €')->assertSee('2,7 l (7,41 € za 1 l)')
            ->assertDontSee('data-lavaggio-countdown', false)->assertDontSee('NC 30 dana:')
            ->assertDontSee('-20%');
    }

    public function test_stock_boundary_delivery_modes_and_configurable_shipping_are_presented_correctly(): void
    {
        $product = $this->product();
        $presenter = app(LavaggioProductPresenter::class);
        foreach ([0 => 'NEDOSTUPNO', 1 => 'ZADNJI KOMADI', 2 => 'ZADNJI KOMADI', 3 => 'NA STANJU', 10 => 'NA STANJU'] as $quantity => $label) {
            $product->stock_qty = $quantity;
            $this->assertSame($label, $presenter->stock($product)['label']);
        }
        $product->stock_qty = 0;
        foreach (['stock' => 'Trenutno nedostupno', 'supplier' => 'Dostava u 4–10 radnih dana', 'order' => 'Dostava od 10 do 20 dana', 'unavailable' => 'Trenutno nedostupno'] as $mode => $label) {
            $product->delivery_mode = $mode;
            $this->assertSame($label, $presenter->delivery($product));
        }
        app(SystemSettingsService::class)->put('lavaggio_free_shipping_threshold', 75);
        $this->get(route('products.show', 'profesionalni-deterdzent'))->assertOk()
            ->assertSee('Besplatna dostava iznad 75,00 €');
    }

    public function test_other_storefronts_keep_their_existing_product_presentation(): void
    {
        $this->product();
        config()->set('storefront.home', 'auto');
        $this->get(route('products.show', 'profesionalni-deterdzent'))->assertOk()
            ->assertDontSee('data-lavaggio-legal-panel', false)->assertDontSee('lavaggio-product-facts')
            ->assertDontSee('Profesionalni proizvod');
    }

    public function test_lavaggio_navigation_does_not_link_to_suppressed_specifications(): void
    {
        config()->set('storefront.theme', 'auto');
        $product = $this->product();
        $product->technicalSpecificationRows()->create([
            'source' => 'catalog', 'source_key' => 'fabric', 'group_name' => 'Tkanina',
            'item_name' => 'Vrsta tkanine', 'values' => ['Pamuk'],
        ]);

        $this->get(route('products.show', 'profesionalni-deterdzent'))->assertOk()
            ->assertSee('data-product-detail-tabs', false)
            ->assertSee('href="#product-description"', false)
            ->assertSee('href="#product-comments"', false)
            ->assertDontSee('href="#product-specifications"', false)
            ->assertDontSee('id="product-specifications"', false);
    }

    public function test_related_cards_keep_their_own_skus_outside_the_main_product_js_update_targets(): void
    {
        $product = $this->product();
        $related = collect(['RELATED-003', 'RELATED-018'])->map(function (string $sku): Product {
            $relatedProduct = Product::create([
                'code' => $sku, 'sku' => $sku, 'is_active' => true, 'base_price' => 12, 'stock_qty' => 5,
            ]);
            $relatedProduct->translations()->create([
                'locale' => 'hr', 'name' => $sku, 'slug' => strtolower($sku),
            ]);

            return $relatedProduct;
        });
        $product->update(['payload' => ['related_product_ids' => $related->pluck('id')->all()]]);
        $response = $this->get(route('products.show', 'profesionalni-deterdzent'))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        foreach ($related as $relatedProduct) {
            $card = $xpath->query('//article[@data-product-card and @data-product-id="'.$relatedProduct->id.'"]')->item(0);
            $this->assertNotNull($card);
            $this->assertSame($relatedProduct->sku, trim($xpath->query('.//*[@data-product-code]', $card)->item(0)->textContent));
            // product-detail.js updates every element matching this selector on option changes.
            $this->assertSame(0, $xpath->query('.//*[@data-product-sku-value]', $card)->length);
        }
        $this->assertSame('PRO-LAUNDRY-1', trim($xpath->query('//*[@data-product-detail]//*[@data-product-code and @data-product-sku-value]')->item(0)->textContent));
    }

    public function test_supplier_and_order_stock_use_availability_labels_without_displaying_quantities(): void
    {
        $product = $this->product();
        $presenter = app(LavaggioProductPresenter::class);
        foreach (['supplier' => 'kod dobavljača', 'order' => 'raspoloživo za narudžbu'] as $mode => $suffix) {
            $product->update(['stock_qty' => 0, 'supplier_stock_qty' => 10, 'delivery_mode' => $mode]);
            $this->assertTrue($product->storefrontIsPurchasable());
            $this->assertSame('NA STANJU', $presenter->stock($product)['label']);
            $this->assertSame(['label' => 'NA STANJU', 'state' => 'available'], $presenter->stock($product));
            $this->get(route('products.show', 'profesionalni-deterdzent'))->assertOk()
                ->assertDontSee('10 kom. '.$suffix)->assertDontSee('lavaggio-stock-quantity', false)
                ->assertSee('NA STANJU')->assertDontSee('NEDOSTUPNO');

            $product->supplier_stock_qty = 2;
            $this->assertSame('ZADNJI KOMADI', $presenter->stock($product)['label']);
            $product->supplier_stock_qty = 0;
            $this->assertSame('NEDOSTUPNO', $presenter->stock($product)['label']);
        }
    }

    private function product(): Product
    {
        $product = Product::create([
            'code' => 'LAUNDRY-1', 'sku' => 'PRO-LAUNDRY-1', 'barcode' => '4000000000018',
            'is_active' => true, 'is_professional' => true, 'base_price' => 20, 'stock_qty' => 2,
            'content_quantity' => 2.7, 'content_unit' => 'l', 'listing_price' => 20,
            'listed_at' => '2026-09-10', 'delivery_mode' => 'stock',
            'ingredients' => 'Površinski aktivne tvari.', 'hazard_notes' => 'Čuvati izvan dohvata djece.',
            'manufacturer_importer' => 'Primjer proizvođača, Zagreb.', 'country_of_origin' => 'Njemačka',
        ]);
        $product->translations()->create([
            'locale' => 'hr', 'name' => 'Profesionalni deterdžent', 'slug' => 'profesionalni-deterdzent',
            'description' => '<p>Deterdžent za profesionalnu njegu rublja.</p>',
        ]);

        return $product;
    }

    private function action(Product $product): CatalogAction
    {
        $action = CatalogAction::create([
            'code' => 'LAUNDRY-SALE', 'scope' => CatalogAction::SCOPE_PRODUCT,
            'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => 20,
            'target_type' => CatalogAction::TARGET_PRODUCT, 'audience_type' => CatalogAction::AUDIENCE_ALL,
            'is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(3),
        ]);
        $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id]);

        return $action;
    }
}
