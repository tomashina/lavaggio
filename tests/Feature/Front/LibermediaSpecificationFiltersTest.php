<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Catalog\LibermediaSpecificationFilterIndex;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LibermediaSpecificationFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('hr');
        config(['storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia', 'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Liber Media', 'store_pricing_b2c_enabled' => true, 'front_category_products_per_page_desktop' => 4]);
    }

    public function test_only_explicit_supported_facts_are_normalized_and_original_description_is_preserved(): void
    {
        $category = $this->category('toys');
        $html = '<h2>Prednosti razvoja</h2><table><tr><th>Aktivnost</th><th>Prednosti</th></tr><tr><td>Fina motorika</td><td>Drvo, plastika i boje za razvoj.</td></tr></table>'
            .'<h2>Specifikacije</h2><ul><li><strong>Materijal:</strong> Visokokvalitetno drvo i izdržljiva plastika.</li><li><strong>Dob:</strong> Namijenjeno za djecu od 18+ mjeseci.</li><li><strong>Boja:</strong> Crvena i plava.</li><li><strong>Broj komada:</strong> 24 komada</li><li><strong>Dimenzije:</strong> 20 x 30 x 5 cm</li></ul>';
        $product = $this->product('explicit', $category, $html);
        $facts = collect(data_get($product->fresh()->payload, 'specification_filter_index.facts'));
        $this->assertEqualsCanonicalizing(['Drvo', 'Plastika'], $facts->where('group', 'material')->pluck('value')->all());
        $this->assertSame(['18+ mjeseci'], $facts->where('group', 'age')->pluck('value')->values()->all());
        $this->assertEqualsCanonicalizing(['Crvena', 'Plava'], $facts->where('group', 'color')->pluck('value')->all());
        $this->assertSame(['24'], $facts->where('group', 'pieces')->pluck('value')->values()->all());
        $this->assertSame(['20 × 30 × 5 cm'], $facts->where('group', 'dimensions')->pluck('value')->values()->all());
        $this->assertSame($html, $product->translations()->first()->description);
        $this->assertNotContains('Fina motorika', Attribute::with('translations')->get()->flatMap->translations->pluck('name')->all());
        $this->assertSame('Materijal', $facts->where('value', 'Drvo')->first()['source_label']);
        $this->assertStringStartsWith('description:', $facts->first()['origin']);
        $this->assertFalse(app(LibermediaSpecificationFilterIndex::class)->sync($product->fresh()));
    }

    public function test_narrative_unlabelled_and_ambiguous_facts_do_not_become_filters(): void
    {
        $product = $this->product('narrative', $this->category('stories'), '<p>Izrađeno od drva za djecu od 3 godine, crvena boja.</p><table><tr><td>Aktivnost</td><td>Materijal i dob za svako dijete</td></tr></table><p>Materijal: Bez plastike.</p><p>Dob: 1,5+ godina</p><p>Dimenzije: Veliki svijet za najljepšu igru.</p><p>Boja: Bez boje.</p><p>Stanje zalihe: 11</p><p>Broj komada: 4 su trenutno na zalihi.</p><p>Broj komada u pakiranju: 0</p>');
        $this->assertSame([], app(LibermediaSpecificationFilterIndex::class)->facts($product));
        $this->assertSame(0, $product->attributes()->count());
    }

    public function test_pack_counts_are_clear_labels_with_stable_filter_identity_and_are_independent_of_stock(): void
    {
        $category = $this->category('sets');
        $description = '<p>Broj dijelova u pakiranju: 11</p>';
        $product = $this->product('eleven-pieces', $category, $description);
        $product->update(['stock_qty' => 4]);
        $attribute = $product->attributes()->with(['translations', 'group.translations'])->firstOrFail();
        $originalId = $attribute->id;
        $originalCode = $attribute->code;
        $attribute->translations()->where('locale', 'hr')->update(['name' => '11', 'group_name' => 'Broj komada']);
        $attribute->group->translations()->where('locale', 'hr')->update(['name' => 'Broj komada']);
        app(LibermediaSpecificationFilterIndex::class)->sync($product->fresh());
        $response = $this->get(route('categories.show', ['slug' => 'sets', 'attr_libermedia_spec_pieces' => $originalId]))->assertOk();
        $filter = collect($response->viewData('attributeFilters'))->firstWhere('query_key', 'attr_libermedia_spec_pieces');
        $this->assertSame('Broj komada u pakiranju', $filter['label']);
        $this->assertSame('11 kom.', $filter['values'][0]['label']);
        $this->assertSame((string) $originalId, (string) $filter['values'][0]['id']);
        $this->assertSame([$product->id], $response->viewData('products')->pluck('id')->all());
        $this->assertSame($originalCode, $product->attributes()->first()->code);
        $this->assertSame('11', collect(data_get($product->fresh()->payload, 'specification_filter_index.facts'))->firstWhere('group', 'pieces')['value']);
        $this->assertSame($description, $product->translations()->first()->description);
        $this->assertSame('11 kom.', $attribute->fresh()->translations()->first()->name);
        $this->assertSame('Broj komada u pakiranju', $attribute->group->fresh()->translations()->first()->name);
    }

    public function test_category_filters_only_offer_real_values_from_active_products_and_correct_combined_counts(): void
    {
        $toys = $this->category('toys');
        $child = $this->category('blocks', $toys);
        $other = $this->category('school');
        $woodThree = $this->product('wood-three', $child, '<p>Materijal: Drvo</p><p>Dob: Od 3 godine</p>');
        $woodThree->categories()->attach($toys, ['is_primary' => false]);
        $woodFour = $this->product('wood-four', $child, '<p>Materijal: Drvo</p><p>Dob: Od 4 godine</p>');
        $plasticThree = $this->product('plastic-three', $toys, '<p>Materijal: Plastika</p><p>Dob: Od 3 godine</p>');
        $this->product('outside', $other, '<p>Materijal: Metal</p><p>Dob: Od 8 godina</p>');
        $this->product('inactive', $toys, '<p>Materijal: Guma</p><p>Dob: Od 9 godina</p>', false);
        $url = route('categories.show', ['slug' => 'toys']);
        $facetCountQueries = [];
        DB::listen(function ($query) use (&$facetCountQueries): void {
            if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'products_count') && str_contains($query->sql, 'catalog_attributes')) {
                $facetCountQueries[] = $query->sql;
            }
        });
        $response = $this->get($url)->assertOk();
        $this->assertCount(1, $facetCountQueries, 'All specification groups and counts must share one scoped query.');
        $filters = collect($response->viewData('attributeFilters'))->keyBy('query_key');
        $material = collect($filters['attr_libermedia_spec_material']['values'])->keyBy('label');
        $age = collect($filters['attr_libermedia_spec_age']['values'])->keyBy('label');
        $this->assertSame(['Drvo', 'Plastika'], $material->keys()->all());
        $this->assertSame(2, $material['Drvo']['count']);
        $this->assertSame(1, $material['Plastika']['count']);
        $this->assertSame(2, $age['3+ godina']['count']);
        $this->assertSame(1, $age['4+ godina']['count']);
        $this->assertSame(3, $response->viewData('products')->total());
        $this->assertSame(4, substr_count($response->getContent(), 'name="attr_libermedia_spec_material"'));
        $selected = ['attr_libermedia_spec_material' => $material['Drvo']['id'], 'attr_libermedia_spec_age' => $age['3+ godina']['id']];
        $chosen = $this->get($url.'?'.http_build_query($selected))->assertOk();
        $this->assertSame([$woodThree->id], $chosen->viewData('products')->pluck('id')->all());
        $chosenFilters = collect($chosen->viewData('attributeFilters'))->keyBy('query_key');
        $this->assertSame(1, collect($chosenFilters['attr_libermedia_spec_material']['values'])->firstWhere('label', 'Plastika')['count']);
        $this->assertSame(1, collect($chosenFilters['attr_libermedia_spec_age']['values'])->firstWhere('label', '4+ godina')['count']);
        $this->assertNotSame($woodFour->id, $chosen->viewData('products')->first()->id);
        $this->assertNotSame($plasticThree->id, $chosen->viewData('products')->first()->id);
    }

    public function test_selected_specifications_survive_sort_pagination_and_ajax_mobile_filter_markup(): void
    {
        $category = $this->category('games');
        $ids = [];
        for ($i = 0; $i < 6; $i++) {
            $ids[] = $this->product('game-'.$i, $category, '<p>Materijal: Drvo</p><p>Dob: 3+ godina</p>')->id;
        }
        $this->product('plastic', $category, '<p>Materijal: Plastika</p><p>Dob: 3+ godina</p>');
        $material = Attribute::whereHas('translations', fn ($q) => $q->where('name', 'Drvo'))->first();
        $query = ['category' => 'games', 'attr_libermedia_spec_material' => $material->id, 'sort' => 'oldest'];
        $first = $this->get(route('shop.index', $query))->assertOk();
        $this->assertSame(6, $first->viewData('products')->total());
        $this->assertSame(array_slice($ids, 0, 4), $first->viewData('products')->pluck('id')->all());
        $next = $first->viewData('products')->nextPageUrl();
        $this->assertStringContainsString('attr_libermedia_spec_material='.$material->id, $next);
        $second = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)'])->get($next)->assertOk();
        $this->assertSame(array_slice($ids, 4), $second->viewData('products')->pluck('id')->all());
        $second->assertSee('catalog-mobile-filter-section', false)->assertSee('catalog-sidebar-section', false)->assertSee('name="attr_libermedia_spec_material"', false);
    }

    public function test_editorial_and_structured_specification_changes_refresh_the_index_and_cached_facets(): void
    {
        $category = $this->category('edit');
        $product = $this->product('editable', $category, '<p>Materijal: Drvo</p>');
        $url = route('categories.show', ['slug' => 'edit']);
        $initial = $this->get($url)->assertOk();
        $this->assertSame('Drvo', $initial->viewData('attributeFilters')[0]['values'][0]['label']);
        $product->translations()->first()->update(['description' => '<p>Materijal: Plastika</p>']);
        $updated = $this->get($url)->assertOk();
        $this->assertSame('Plastika', $updated->viewData('attributeFilters')[0]['values'][0]['label']);
        $spec = $product->technicalSpecificationRows()->create(['source' => 'libermedia', 'source_key' => hash('sha256', 'age'), 'group_name' => 'Specifikacije', 'item_name' => 'Dob', 'values' => ['3+ godina']]);
        $withAge = collect($this->get($url)->assertOk()->viewData('attributeFilters'))->keyBy('query_key');
        $this->assertSame('3+ godina', $withAge['attr_libermedia_spec_age']['values'][0]['label']);
        $spec->delete();
        $this->assertArrayNotHasKey('attr_libermedia_spec_age', collect($this->get($url)->assertOk()->viewData('attributeFilters'))->keyBy('query_key')->all());
        DB::table('product_translations')->where('product_id', $product->id)->update(['description' => '<p>Materijal: Metal</p>']);
        $this->artisan('libermedia:index-specification-filters')->assertSuccessful();
        $this->assertSame('Metal', $this->get($url)->assertOk()->viewData('attributeFilters')[0]['values'][0]['label']);
        $this->assertSame('<p>Materijal: Metal</p>', $product->translations()->first()->description);
    }

    private function category(string $slug, ?Category $parent = null): Category
    {
        $category = Category::create(['code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'parent_id' => $parent?->id, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'name' => ucfirst($slug), 'slug' => $slug]);

        return $category;
    }

    private function product(string $code, Category $category, string $description, bool $active = true): Product
    {
        $product = Product::create(['code' => $code, 'sku' => Str::upper($code), 'base_price' => 10, 'stock_qty' => 20, 'is_active' => $active]);
        $product->categories()->attach($category, ['is_primary' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code, 'description' => $description]);

        return $product;
    }
}
