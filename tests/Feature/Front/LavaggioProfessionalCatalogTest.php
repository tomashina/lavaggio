<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioProfessionalCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true, 'app.locale' => 'hr']);
        app()->setLocale('hr');
    }

    public function test_the_pro_catalog_includes_both_markers_and_keeps_category_filters_and_counts_scoped(): void
    {
        $pro = Attribute::create(['code' => 'lavaggio-pro-pro', 'group_code' => 'lavaggio-pro', 'is_active' => true]);
        $fabric = Attribute::create(['code' => 'lavaggio-fabric-pamuk', 'group_code' => 'lavaggio-fabric', 'is_active' => true]);
        app(SystemSettingsService::class)->put('store_product_filter_attribute_group_codes', ['lavaggio-pro', 'lavaggio-fabric']);
        $category = Category::create(['code' => 'pranje', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG, 'slug' => 'pranje', 'name' => 'Pranje']);
        $flagged = $this->product('oznacen', professional: true);
        $tagged = $this->product('s-atributom');
        $both = $this->product('obje-oznake', professional: true);
        $regular = $this->product('kucni');
        $hidden = $this->product('skriven', professional: true, active: false);
        $tagged->attributes()->attach([$pro->id, $fabric->id]);
        $both->attributes()->attach($pro);
        $regular->attributes()->attach($fabric);
        $category->products()->attach([$tagged->id, $regular->id, $hidden->id]);

        $this->get('/trgovina')->assertOk()->assertViewHas('products', fn ($page) => $page->total() === 4);
        $this->get('/trgovina/pro')->assertOk()->assertSeeText('Profesionalni proizvodi')
            ->assertViewHas('products', fn ($page) => $page->getCollection()->modelKeys() === [$both->id, $tagged->id, $flagged->id])
            ->assertViewHas('categories', fn ($rows) => $rows->firstWhere('id', $category->id)->products_count == 1)
            ->assertViewHas('attributeFilters', fn ($filters) => count($filters) === 1 && $filters[0]['values'][0]['count'] === 1)
            ->assertSee('action="'.route('shop.pro').'"', false)
            ->assertSee(route('shop.pro', ['category' => 'pranje']), false);
        $this->get('/trgovina/pro?category=pranje&attr_lavaggio_pro=0&sort=oldest')->assertOk()
            ->assertViewHas('products', fn ($page) => $page->getCollection()->modelKeys() === [$tagged->id]);
        $this->get('/trgovina/pro?attr_lavaggio_fabric='.$fabric->id)->assertOk()
            ->assertViewHas('products', fn ($page) => $page->getCollection()->modelKeys() === [$tagged->id]);
        $this->get('/trgovina?attr_lavaggio_pro='.$pro->id.'&sort=oldest&cols=5')->assertStatus(301)
            ->assertRedirect(route('shop.pro', ['sort' => 'oldest', 'cols' => 5]));
    }

    public function test_empty_pro_catalog_and_pagination_stay_on_the_new_address(): void
    {
        $this->product('kucni');
        $this->get('/trgovina/pro')->assertOk()->assertSeeText('Trenutno nema profesionalnih proizvoda.');
        app(SystemSettingsService::class)->put('front_category_products_per_page_desktop', 4);
        foreach (range(1, 5) as $i) {
            $this->product('pro-'.$i, professional: true);
        }
        $this->get('/trgovina/pro?sort=oldest')->assertOk()
            ->assertViewHas('products', fn ($page) => $page->total() === 5 && $page->nextPageUrl() === route('shop.pro', ['sort' => 'oldest', 'page' => 2]));
        $this->get('/trgovina/pro?sort=oldest&page=2')->assertOk()
            ->assertViewHas('products', fn ($page) => $page->pluck('code')->all() === ['pro-5']);
        config(['storefront.home' => 'watchline']);
        $this->get('/trgovina/pro')->assertNotFound();
    }

    private function product(string $code, bool $professional = false, bool $active = true): Product
    {
        $product = Product::create(['code' => $code, 'is_active' => $active, 'is_professional' => $professional, 'stock_qty' => 2, 'base_price' => 10]);
        $product->translations()->create(['locale' => 'hr', 'slug' => $code, 'name' => $code]);

        return $product;
    }
}
