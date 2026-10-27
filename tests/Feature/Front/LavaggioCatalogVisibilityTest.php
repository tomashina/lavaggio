<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LavaggioCatalogVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.name' => 'Lavaggio',
            'app.locale' => 'hr',
            'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera',
            'storefront.brand' => 'libermedia',
            'storefront_cache.enabled' => false,
        ]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('store_brand_name', 'Lavaggio');
    }

    public function test_empty_active_categories_remain_browsable_with_descriptions_and_seo_metadata(): void
    {
        $root = $this->category('Deterdženti', 'deterdzenti');
        $child = $this->category('Bijelo rublje', 'bijelo-rublje', $root);
        $this->category('Neaktivno', 'neaktivno', attributes: ['is_active' => false]);
        $this->category('Uskoro', 'uskoro', attributes: ['starts_at' => now()->addDay()]);
        $this->category('Isteklo', 'isteklo', attributes: ['ends_at' => now()->subDay()]);
        $this->category('Blog', 'blog-kategorija', attributes: ['scope' => Category::SCOPE_BLOG]);

        $shop = $this->get(route('shop.index'))->assertOk()
            ->assertViewHas('categories', fn ($categories) => $categories->pluck('id')->all() === [$root->id]);
        $this->assertFilterLink($shop->getContent(), route('categories.show', 'deterdzenti'));

        $category = $this->get(route('categories.show', 'deterdzenti'))->assertOk()
            ->assertViewHas('categories', fn ($categories) => $categories->pluck('id')->all() === [$root->id, $child->id])
            ->assertViewHas('subcategories', fn ($categories) => $categories->pluck('id')->all() === [$child->id])
            ->assertViewHas('subcategoryCards', fn ($categories) => $categories->pluck('id')->all() === [$child->id]);
        $this->assertFilterLink($category->getContent(), route('categories.show', 'bijelo-rublje'));

        foreach (['deterdzenti' => 'Deterdženti', 'bijelo-rublje' => 'Bijelo rublje'] as $slug => $name) {
            $response = $this->get(route('categories.show', $slug))->assertOk();
            $xpath = $this->xpath($response->getContent());
            $this->assertSame($name, trim($xpath->query('//main//h1')->item(0)->textContent));
            $this->assertSame($name.' | Lavaggio', $xpath->query('//title')->item(0)->textContent);
            $this->assertSame('SEO opis za '.$name.'.', $xpath->query('//meta[@name="description"]')->item(0)->getAttribute('content'));
            $this->assertSame('Opis kategorije '.$name.'.', trim($xpath->query('//main//*[@data-category-long-description]')->item(0)->textContent));
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_lavaggio_empty_category_snapshots_do_not_change_other_storefronts(): void
    {
        $root = $this->category('Prazna kategorija', 'prazna-kategorija');
        $this->category('Prazna podkategorija', 'prazna-podkategorija', $root);
        $this->get(route('shop.index'))->assertOk()->assertViewHas('categories', fn ($categories) => $categories->count() === 1);
        $this->get(route('categories.show', 'prazna-kategorija'))->assertOk()
            ->assertViewHas('categories', fn ($categories) => $categories->count() === 2);

        config()->set('storefront.home', 'auto');
        $this->get(route('shop.index'))->assertOk()->assertViewHas('categories', fn ($categories) => $categories->isEmpty());
        $this->get(route('categories.show', 'prazna-kategorija'))->assertOk()
            ->assertViewHas('categories', fn ($categories) => $categories->isEmpty());
    }

    private function category(string $name, string $slug, ?Category $parent = null, array $attributes = []): Category
    {
        $category = new Category(array_merge([
            'scope' => Category::SCOPE_CATALOG,
            'code' => $slug,
            'is_active' => true,
            'show_in_menu' => true,
        ], $attributes));
        $parent ? $category->appendToNode($parent)->save() : $category->saveAsRoot();
        $category->translations()->create([
            'scope' => $category->scope,
            'locale' => 'hr',
            'name' => $name,
            'slug' => $slug,
            'description' => '<p>Opis kategorije '.$name.'.</p>',
            'meta_title' => $name.' | Lavaggio',
            'meta_description' => 'SEO opis za '.$name.'.',
        ]);

        return $category;
    }

    private function assertFilterLink(string $html, string $url): void
    {
        $xpath = $this->xpath($html);
        foreach (['catalog-mobile-filter-options', 'catalog-sidebar-category-options'] as $class) {
            $this->assertSame(1, $xpath->query('//main//nav[contains(@class,"'.$class.'")]//a[@href="'.$url.'"]')->count());
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }
}
