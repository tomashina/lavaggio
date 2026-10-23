<?php

namespace Tests\Feature\Front;

use App\Livewire\Admin\Catalog\Category\Form as CategoryForm;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\Language;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CategoryDescriptionFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront_cache.enabled' => false]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Liber Media');
    }

    public function test_original_short_introduction_precedes_products_and_full_description_follows_the_grid(): void
    {
        $category = $this->category([
            'description' => '<h2>Dugi opis kategorije</h2><p>Detalji koji pripadaju ispod proizvoda.</p>',
            'payload' => ['opencart' => ['short_description' => '&lt;h2&gt;&amp;Scaron;to biramo?&lt;/h2&gt;&lt;p&gt;Izvorni &lt;strong&gt;kratki uvod&lt;/strong&gt;.&lt;/p&gt;']],
        ]);
        $this->product($category);

        $response = $this->get(route('categories.show', ['slug' => 'igracke']))->assertOk()
            ->assertSeeInOrder(['data-category-short-description', 'data-catalog-grid', 'data-category-long-description'], false)
            ->assertSee('category-descriptions.css', false);
        $xpath = $this->xpath($response->getContent());
        $short = $xpath->query('//*[@data-category-short-description]')->item(0);
        $long = $xpath->query('//*[@data-category-long-description]')->item(0);
        $this->assertStringContainsString('Što biramo?', $short->textContent);
        $this->assertStringNotContainsString('Dugi opis kategorije', $short->textContent);
        $this->assertStringContainsString('Dugi opis kategorije', $long->textContent);
        $this->assertStringNotContainsString('Izvorni kratki uvod', $long->textContent);
        $this->assertSame(1, $xpath->query('.//strong', $short)->count());
        $this->assertSame(1, $xpath->query('//*[@data-category-short-description]')->count());
        $this->assertSame(1, $xpath->query('//*[@data-category-long-description]')->count());
    }

    public function test_categories_without_an_original_short_description_do_not_get_a_generated_excerpt(): void
    {
        $category = $this->category(['description' => '<h2>Samo dugi opis</h2><p>Tekst je sačuvan ispod proizvoda.</p>']);
        $this->product($category);

        $this->get(route('categories.show', ['slug' => 'igracke']))->assertOk()
            ->assertDontSee('data-category-short-description', false)
            ->assertSeeInOrder(['data-catalog-grid', 'data-category-long-description', 'Samo dugi opis'], false);
    }

    public function test_formatting_is_preserved_while_unsafe_imported_markup_is_removed(): void
    {
        $this->category([
            'description' => '<h2>Naslov sadržaja</h2><ul><li>Prva stavka</li></ul><table><tr><th>Dob</th><td>3 godine</td></tr></table><p><a href="https://www.liber-media.hr/blog" onclick="evilCategory()">Pročitaj više</a></p><script>evilCategory()</script>',
            'payload' => ['opencart' => ['short_description' => '<p style="color:pink" onmouseover="evilCategory()">Uvod</p><iframe src="https://evil.test"></iframe>']],
        ]);

        $response = $this->get(route('categories.show', ['slug' => 'igracke']))->assertOk()
            ->assertSee('Naslov sadržaja')->assertSee('Pročitaj više');
        $xpath = $this->xpath($response->getContent());
        $long = $xpath->query('//*[@data-category-long-description]')->item(0);
        $short = $xpath->query('//*[@data-category-short-description]')->item(0);
        $descriptionHtml = $long->ownerDocument->saveHTML($long).$short->ownerDocument->saveHTML($short);
        $this->assertStringNotContainsString('evilCategory()', $descriptionHtml);
        $this->assertStringNotContainsString('color:pink', $descriptionHtml);
        $this->assertStringNotContainsString('https://evil.test', $descriptionHtml);
        $this->assertSame(1, $xpath->query('.//h2', $long)->count());
        $this->assertSame(1, $xpath->query('.//ul/li', $long)->count());
        $this->assertSame(1, $xpath->query('.//table//th', $long)->count());
        $this->assertSame('https://www.liber-media.hr/blog', $xpath->query('.//a', $long)->item(0)->getAttribute('href'));
    }

    public function test_repeated_long_content_is_not_displayed_as_a_short_introduction_and_empty_editors_are_hidden(): void
    {
        $category = $this->category([
            'description' => '<p>Isti sadržaj.</p>',
            'payload' => ['opencart' => ['short_description' => '<div>Isti sadržaj.</div>']],
        ]);
        $this->get(route('categories.show', ['slug' => 'igracke']))->assertOk()
            ->assertDontSee('data-category-short-description', false)->assertSee('data-category-long-description', false);

        $category->translations()->first()->update(['description' => '<p>&nbsp;<br></p>', 'payload' => ['short_description' => '<p><br></p>']]);
        $this->get(route('categories.show', ['slug' => 'igracke']))->assertOk()
            ->assertDontSee('data-category-short-description', false)->assertDontSee('data-category-long-description', false);
    }

    public function test_current_translation_controls_both_descriptions_and_category_only_landing_pages_keep_them(): void
    {
        Language::query()->create(['code' => 'en', 'locale' => 'en', 'name' => 'English', 'is_active' => true, 'is_default' => true]);
        $category = $this->category(['description' => '<p>Hrvatski dugi opis</p>', 'payload' => ['short_description' => '<p>Hrvatski uvod</p>']]);
        $category->translations()->create(['scope' => 'catalog', 'locale' => 'en', 'name' => 'Toys', 'slug' => 'toys',
            'description' => '<p>Full English description</p>', 'payload' => ['short_description' => '<p>English introduction</p>']]);
        $category->update(['payload' => [Category::PAYLOAD_SHOW_PRODUCTS => false, Category::PAYLOAD_SHOW_FILTERS => false]]);

        $this->withSession(['front_locale' => 'en'])->get(route('categories.show', ['slug' => 'toys']))->assertOk()
            ->assertSeeInOrder(['data-category-short-description', 'English introduction', 'data-category-long-description', 'Full English description'], false)
            ->assertDontSee('Hrvatski dugi opis')->assertDontSee('Hrvatski uvod')->assertDontSee('data-catalog-grid', false);
    }

    public function test_admin_can_edit_and_clear_short_text_without_losing_the_original_import_or_full_description(): void
    {
        $original = '&lt;p&gt;Izvorni uvod&lt;/p&gt;';
        $category = $this->category(['description' => '<p>Izvorni dugi opis</p>', 'payload' => ['opencart' => ['short_description' => $original], 'custom_marker' => 'preserve']]);
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        Livewire::actingAs($admin)->test(CategoryForm::class, ['categoryId' => $category->id])
            ->assertSee('Kratki opis')->assertSee('Dugi opis')
            ->assertSet('form.short_description', '<p>Izvorni uvod</p>')
            ->set('form.short_description', '<p>Novi kratki uvod</p>')->call('save')->assertHasNoErrors();

        $translation = $category->translations()->first();
        $this->assertSame('<p>Novi kratki uvod</p>', $translation->short_description);
        $this->assertSame($original, data_get($translation->payload, 'opencart.short_description'));
        $this->assertSame('preserve', data_get($translation->payload, 'custom_marker'));
        $this->assertSame('<p>Izvorni dugi opis</p>', $translation->description);

        Livewire::actingAs($admin)->test(CategoryForm::class, ['categoryId' => $category->id])
            ->set('form.short_description', '')->call('save')->assertHasNoErrors();
        $this->assertSame('', $translation->fresh()->short_description);
        $this->get(route('categories.show', ['slug' => 'igracke']))->assertOk()
            ->assertDontSee('data-category-short-description', false)->assertSee('data-category-long-description', false);
    }

    private function category(array $translationAttributes = []): Category
    {
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'igracke', 'is_active' => true, 'show_in_menu' => true]);
        $category->translations()->create(array_replace(['scope' => 'catalog', 'locale' => 'hr', 'name' => 'Igračke', 'slug' => 'igracke'], $translationAttributes));

        return $category;
    }

    private function product(Category $category): void
    {
        $product = Product::query()->create(['code' => 'test-product', 'sku' => 'TEST', 'base_price' => 10, 'stock_qty' => 5, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Testni proizvod', 'slug' => 'test-product']);
        $product->categories()->attach($category);
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
