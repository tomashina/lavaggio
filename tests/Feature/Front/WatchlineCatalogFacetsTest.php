<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Catalog\WatchlineCatalogFacets;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchlineCatalogFacetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'WATCH LINE AM - Web Shop',
            'catalog_hide_out_of_stock_products' => true, 'catalog_use_manufacturers' => true,
            'front_category_products_per_page_desktop' => 4,
            'store_product_filter_attribute_group_codes' => ['libermedia-spec-age', 'material']]);
    }

    public function test_exact_five_real_facets_use_category_descendants_and_distinct_active_product_counts(): void
    {
        $root = $this->category('satovi');
        $child = $this->category('muski-satovi', $root);
        $leaf = $this->category('automatic-satovi', $child);
        $outside = $this->category('drugo');
        $silver = $this->attribute(38, 'Srebrna');
        $black = $this->attribute(38, 'Crna');
        $water = $this->attribute(15, '5 ATM / 50 M');
        $diameter = $this->attribute(17, '45mm');
        $movement = $this->attribute(19, 'Automatic');
        $collection = $this->attribute(21, 'Classic');
        $extra = $this->attribute('material', 'Čelik', 'Materijal');
        $watch = $this->product('silver-watch', [$child, $leaf, $root], [$silver, $water, $diameter, $movement, $collection, $extra]);
        $this->product('black-watch', [$leaf], [$black, $water, $diameter, $movement, $collection]);
        $this->product('inactive-watch', [$root], [$this->attribute(38, 'Samo neaktivni')], active: false);
        $this->product('out-of-stock', [$root], [$this->attribute(38, 'Bez zalihe')], stock: 0);
        $this->product('outside', [$outside], [$this->attribute(38, 'Izvan kategorije')]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->get(route('categories.show', ['slug' => 'satovi']))->assertOk();
            $facetQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'COUNT(DISTINCT facet_products.id)'));
        } finally {
            DB::disableQueryLog();
        }
        $filters = collect($response->viewData('attributeFilters'))->keyBy('query_key');
        $this->assertSame(['Boja kućišta', 'Vodootpornost', 'Promjer kućišta', 'Mehanizam', 'Kolekcija'], $filters->pluck('label')->all());
        $colors = collect($filters['attr_watchline_case_color']['values'])->keyBy('label');
        $this->assertSame(['Crna', 'Srebrna'], $colors->keys()->all());
        $this->assertSame(1, $colors['Srebrna']['count']);
        $this->assertSame('45 mm', $filters['attr_watchline_case_diameter']['values'][0]['label']);
        $this->assertSame(2, $filters['attr_watchline_collection']['values'][0]['count']);
        $this->assertSame(2, $response->viewData('products')->total());
        $this->assertCount(1, $facetQueries, 'All five facets share one scoped distinct-product aggregate.');
        $this->assertSame([], $response->viewData('optionFilters'));
        $this->assertSame([$silver->id, $water->id, $diameter->id, $movement->id, $collection->id, $extra->id], $watch->attributes()->pluck('catalog_attributes.id')->all());
        $this->assertArrayNotHasKey('specification_filter_index', $watch->fresh()->payload ?? []);
    }

    public function test_collection_merges_watches_glasses_and_jewellery_and_selects_all_equivalent_original_values(): void
    {
        $root = $this->category('ponuda');
        $watches = $this->category('satovi', $root);
        $glasses = $this->category('naocale', $root);
        $jewellery = $this->category('nakit', $root);
        $watchCollection = $this->attribute(21, 'Classic &amp;amp; Gold');
        $glassesCollection = $this->attribute(26, ' classic & Gold ');
        $jewelleryCollection = $this->attribute(45, 'Classic &amp; Gold');
        $watch = $this->product('watch', [$watches], [$watchCollection]);
        $glassesProduct = $this->product('glasses', [$glasses], [$glassesCollection]);
        $jewel = $this->product('jewel', [$jewellery], [$jewelleryCollection]);
        // A repeated equivalent value on one product must never inflate its count.
        $watch->attributes()->attach($glassesCollection);
        $otherCollection = $this->attribute(26, 'AVIATOR LARGE METAL');
        $this->product('other-glasses', [$glasses], [$otherCollection]);

        foreach ([route('shop.index'), route('categories.show', ['slug' => 'ponuda'])] as $url) {
            $response = $this->get($url)->assertOk();
            $filters = $response->viewData('attributeFilters');
            $this->assertCount(1, $filters);
            $this->assertSame('Kolekcija', $filters[0]['label']);
            $this->assertCount(2, $filters[0]['values']);
            $value = collect($filters[0]['values'])->firstWhere('label', 'Classic & Gold');
            $this->assertSame(3, $value['count']);
            $selected = $this->get($url.'?'.http_build_query(['attr_watchline_collection' => $value['id']]))->assertOk();
            $this->assertEqualsCanonicalizing([$watch->id, $glassesProduct->id, $jewel->id], $selected->viewData('products')->pluck('id')->all());
            $this->assertSame(3, collect($selected->viewData('attributeFilters')[0]['values'])->firstWhere('label', 'Classic & Gold')['count']);
        }
        $response = $this->get(route('categories.show', ['slug' => 'naocale', 'attr_watchline_collection' => $watchCollection->id]))->assertOk();
        $this->assertSame([$glassesProduct->id], $response->viewData('products')->pluck('id')->all());
        $this->assertSame(['Kolekcija'], array_column($response->viewData('attributeFilters'), 'label'));
        $this->assertSame(1, collect($response->viewData('attributeFilters')[0]['values'])->firstWhere('label', 'Classic & Gold')['count']);
    }

    public function test_combined_filters_refine_other_facet_counts_and_keep_collection_self_exclusion_across_source_groups(): void
    {
        $category = $this->category('combined');
        $silver = $this->attribute(38, 'Srebrna');
        $black = $this->attribute(38, 'Crna');
        $quartz = $this->attribute(19, 'Quartz (baterija)');
        $automatic = $this->attribute(19, 'Automatic');
        $firstCollection = $this->attribute(21, 'CLASSIC');
        $equivalentCollection = $this->attribute(26, 'Classic');
        $otherCollection = $this->attribute(26, 'AVIATOR');
        $matching = $this->product('silver-quartz-classic', [$category], [$silver, $quartz, $firstCollection]);
        $this->product('black-quartz-classic', [$category], [$black, $quartz, $equivalentCollection]);
        $this->product('silver-automatic-classic', [$category], [$silver, $automatic, $firstCollection]);
        $this->product('silver-quartz-aviator', [$category], [$silver, $quartz, $otherCollection]);

        $response = $this->get(route('categories.show', ['slug' => 'combined',
            'attr_watchline_case_color' => $silver->id, 'attr_watchline_movement' => $quartz->id,
            'attr_watchline_collection' => $firstCollection->id]))->assertOk();
        $this->assertSame([$matching->id], $response->viewData('products')->pluck('id')->all());
        $filters = collect($response->viewData('attributeFilters'))->keyBy('query_key');
        $this->assertSame(1, collect($filters['attr_watchline_case_color']['values'])->firstWhere('label', 'Crna')['count']);
        $this->assertSame(1, collect($filters['attr_watchline_movement']['values'])->firstWhere('label', 'Automatic')['count']);
        $this->assertSame(1, collect($filters['attr_watchline_collection']['values'])->firstWhere('label', 'AVIATOR')['count']);
    }

    public function test_filter_counts_and_definitions_refresh_immediately_after_attribute_pivot_stock_and_active_edits(): void
    {
        $category = $this->category('editable');
        $silver = $this->attribute(38, 'Srebrna');
        $black = $this->attribute(38, 'Crna');
        $product = $this->product('editable', [$category], [$silver]);
        $url = route('categories.show', ['slug' => 'editable']);
        $this->assertSame('Srebrna', $this->get($url)->assertOk()->viewData('attributeFilters')[0]['values'][0]['label']);
        $revision = Cache::get(WatchlineCatalogFacets::REVISION_KEY);
        $product->attributes()->sync([$black->id]);
        $this->assertNotSame($revision, Cache::get(WatchlineCatalogFacets::REVISION_KEY));
        $this->assertSame('Crna', $this->get($url)->assertOk()->viewData('attributeFilters')[0]['values'][0]['label']);
        $black->translations()->first()->update(['name' => 'Crna mat']);
        $this->assertSame('Crna mat', $this->get($url)->assertOk()->viewData('attributeFilters')[0]['values'][0]['label']);
        $product->update(['stock_qty' => 0]);
        $this->assertSame([], $this->get($url)->assertOk()->viewData('attributeFilters'));
        $product->update(['stock_qty' => 1]);
        $black->update(['is_active' => false]);
        $this->assertSame([], $this->get($url)->assertOk()->viewData('attributeFilters'));
        $this->assertSame([$black->id], $product->attributes()->pluck('catalog_attributes.id')->all());
    }

    public function test_manufacturer_scope_only_offers_its_actual_values_and_selections_survive_pagination(): void
    {
        $category = $this->category('brand-category');
        $brand = Manufacturer::create(['code' => 'guess', 'is_active' => true]);
        $brand->translations()->create(['locale' => 'hr', 'name' => 'GUESS', 'slug' => 'guess']);
        $collection = $this->attribute(21, 'GUESS Collection');
        $ids = [];
        for ($index = 1; $index <= 6; $index++) {
            $product = $this->product('guess-'.$index, [$category], [$collection]);
            $product->update(['manufacturer_id' => $brand->id]);
            $ids[] = $product->id;
        }
        $this->product('outside-brand', [$category], [$this->attribute(21, 'Drugi brand')]);
        $response = $this->get(route('manufacturers.show', ['slug' => 'guess', 'attr_watchline_collection' => $collection->id, 'sort' => 'oldest']))->assertOk();
        $this->assertSame(6, $response->viewData('products')->total());
        $this->assertSame(array_slice($ids, 0, 4), $response->viewData('products')->pluck('id')->all());
        $this->assertCount(1, $response->viewData('attributeFilters')[0]['values']);
        $nextUrl = $response->viewData('products')->nextPageUrl();
        $this->assertStringContainsString('attr_watchline_collection='.$collection->id, $nextUrl);
        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'User-Agent' => 'Mozilla/5.0 (iPhone)'])->get($nextUrl)->assertOk()
            ->assertSee('name="attr_watchline_collection"', false);
    }

    public function test_a_query_cannot_use_an_unrelated_attribute_as_a_case_colour(): void
    {
        $category = $this->category('checked-values');
        $colour = $this->attribute(38, 'Crna');
        $material = $this->attribute('material', 'Čelik');
        $this->product('checked-watch', [$category], [$colour, $material]);

        $response = $this->get(route('categories.show', ['slug' => 'checked-values', 'attr_watchline_case_color' => $material->id]))->assertOk();
        $this->assertSame(0, $response->viewData('products')->total());
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::create(['code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true, 'parent_id' => $parent?->id]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => $slug, 'slug' => $slug]);

        return $category;
    }

    private function attribute(int|string $source, string $value, ?string $label = null): Attribute
    {
        $group = is_int($source) ? 'herrera-oc-attribute-'.$source : $source;
        $code = $group.'-'.substr(hash('sha256', $value), 0, 20);
        $attribute = Attribute::create(['code' => $code, 'group_code' => $group, 'type' => Attribute::TYPE_SELECT, 'is_active' => true,
            'payload' => ['source' => 'herrera-opencart']]);
        $attribute->translations()->create(['locale' => 'hr', 'group_name' => $label ?? 'Originalni naziv grupe', 'name' => $value, 'slug' => $code]);

        return $attribute;
    }

    private function product(string $code, array $categories, array $attributes, bool $active = true, int $stock = 1): Product
    {
        $product = Product::create(['code' => $code, 'is_active' => $active, 'stock_qty' => $stock, 'base_price' => 100]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code,
            'description' => '<p>Marketinški tekst: plavi sat, kolekcija iz mašte.</p>']);
        $product->categories()->attach(collect($categories)->pluck('id'));
        $product->attributes()->attach(collect($attributes)->pluck('id'));

        return $product;
    }
}
