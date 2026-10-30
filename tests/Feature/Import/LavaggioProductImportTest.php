<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Import\LavaggioProductImportService;
use App\Services\Settings\SystemSettingsService;
use App\Support\LavaggioProductFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioProductImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_problem_navigation_stays_associated_when_a_category_slug_changes(): void
    {
        LavaggioProductFilters::seed();
        $category = Category::query()->create(['code' => 'lavaggio-deterdzenti', 'scope' => 'catalog', 'is_active' => true]);
        $links = LavaggioProductFilters::navigationLinks('https://example.com/category/novi-naziv', 'category-'.$category->id);
        $this->assertSame('Sivo bijelo rublje', $links[0]['label']);
        $this->assertStringContainsString('attr_lavaggio_problem=', $links[0]['url']);
    }

    public function test_import_preserves_source_fields_and_connects_problem_navigation_to_working_filters(): void
    {
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false]);
        $category = Category::query()->create(['code' => 'lavaggio-bijelo-rublje', 'scope' => 'catalog', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Bijelo rublje', 'slug' => 'bijelo-rublje']);
        $item = [
            'name' => 'Persil ogledni artikl 2,7 l', 'brand' => 'Persil', 'ean' => '9000100000001', 'price' => 19.95,
            'content_quantity' => 2.7, 'content_unit' => 'l', 'category_slug' => 'bijelo-rublje',
            'description' => '<p>Opis proizvoda.</p>', 'ingredients' => 'Sastojci s izvora.', 'hazard_notes' => 'Napomene s izvora.',
            'manufacturer_importer' => 'Proizvođač s izvora.', 'country_of_origin' => 'EU',
            'source_url' => 'https://example.com/persil', 'image_url' => 'https://example.com/persil.png',
            'source_legal_original' => ['hazard_notes' => 'Izvorna deklaracija.'],
            'attributes' => ['problem' => ['Sivilo bijelog rublja'], 'purpose' => ['Bijelo'], 'package' => ['Kućno pakiranje']],
        ];
        app(SystemSettingsService::class)->put('lavaggio_free_shipping_threshold', 75);
        $importer = app(LavaggioProductImportService::class);
        $other = array_replace($item, ['name' => 'Drugi artikl bez odabranog problema', 'ean' => '9000100000002', 'attributes' => ['purpose' => ['Crno']]]);
        $importer->import([$item, $other], true, false);
        $product = Product::query()->firstOrFail();
        $this->assertSame('9000100000001', $product->barcode);
        $this->assertSame('2.700', $product->content_quantity);
        $this->assertSame('Sastojci s izvora.', $product->ingredients);
        $this->assertSame('Izvorna deklaracija.', $product->payload['lavaggio_import']['source_legal_original']['hazard_notes']);
        $this->assertSame('19.95', $product->listing_price);
        $this->assertSame(25.0, (float) $product->taxRate->rate);
        $this->assertSame(75, app(SystemSettingsService::class)->get('lavaggio_free_shipping_threshold'));
        $this->assertSame(0, $product->comments()->count());
        $this->assertSame(1, CatalogAction::query()->count());

        $link = LavaggioProductFilters::navigationLinks('https://example.com/shop/deterdzenti')[0];
        $attribute = Attribute::query()->where('code', LavaggioProductFilters::attributeCode('problem', 'Sivilo bijelog rublja'))->firstOrFail();
        $this->assertStringContainsString('attr_lavaggio_problem='.$attribute->id, $link['url']);
        $this->get($link['url'])->assertOk()->assertSee('Persil ogledni artikl')->assertDontSee('Drugi artikl bez odabranog problema');

        $product->update(['stock_qty' => 3, 'delivery_mode' => 'order']);
        $importer->import([$item, $other], true, false);
        $this->assertSame(2, Product::query()->count());
        $this->assertSame(1, CatalogAction::query()->count());
        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertSame('order', $product->fresh()->delivery_mode);

        // A later import is a new batch whose row numbers start at zero again.
        $nextBatch = array_replace($item, ['name' => 'Novi artikl iz druge serije', 'ean' => '9000100000003']);
        $importer->import([$nextBatch], false, false);
        $this->assertSame('LAV-0003', Product::query()->where('barcode', '9000100000003')->firstOrFail()->sku);
        $this->assertSame('LAV-0001', $product->fresh()->sku);
        $this->assertSame(3, Product::query()->count());
    }
}
