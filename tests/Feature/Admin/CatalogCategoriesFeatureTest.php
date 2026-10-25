<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Category\Form as CategoryForm;
use App\Models\Catalog\Category\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogCategoriesFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_form_saves_catalog_page_visibility_settings(): void
    {
        config(['app.locale' => 'en']);
        app()->setLocale('en');

        $user = $this->makeAdminUser();

        Livewire::actingAs($user)
            ->test(CategoryForm::class)
            ->set('form.scope', Category::SCOPE_CATALOG)
            ->set('form.locale', 'en')
            ->set('form.code', 'landing-men')
            ->set('form.name', 'Landing Men')
            ->set('form.slug', 'landing-men')
            ->set('form.is_active', true)
            ->set('form.show_in_menu', true)
            ->set('form.catalog_show_filters', false)
            ->set('form.catalog_show_products', false)
            ->call('save')
            ->assertRedirect(route('admin.categories', [
                'scope' => Category::SCOPE_CATALOG,
                'locale' => 'en',
            ]));

        $category = Category::query()->where('code', 'landing-men')->first();

        $this->assertNotNull($category);
        $this->assertSame([
            Category::PAYLOAD_SHOW_FILTERS => false,
            Category::PAYLOAD_SHOW_PRODUCTS => false,
        ], $category->payload);
    }

    public function test_watchline_hides_shipping_fields_and_saves_other_category_fields_without_changing_stored_labels(): void
    {
        config(['storefront.home' => 'watchline', 'app.locale' => 'en']);
        app()->setLocale('en');
        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG, 'code' => 'watches', 'is_active' => true,
            'payload' => [
                Category::PAYLOAD_SHIPPING_LABELS => ['fragile', 'legacy-unknown-label'],
                Category::PAYLOAD_SHOW_FILTERS => true, Category::PAYLOAD_SHOW_PRODUCTS => true,
                'opencart' => ['category_id' => 66, 'image' => 'catalog/watches.jpg'],
                'custom' => ['retain' => true],
            ],
        ]);
        $category->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'en', 'name' => 'Watches', 'slug' => 'watches']);

        Livewire::actingAs($this->makeAdminUser())->test(CategoryForm::class, ['categoryId' => $category->id])
            ->assertDontSee('Pravila dostave kategorije')
            ->assertDontSee('wire:model="form.shipping_labels"', false)
            ->assertSee(__('Toggle category filters visibility'))
            ->assertSee(__('Toggle category product listing visibility'))
            ->set('form.shipping_labels', 'invalid hidden value')
            ->set('form.name', 'Updated watches')
            ->set('form.meta_title', 'Updated category SEO')
            ->set('form.catalog_show_filters', false)
            ->call('save')->assertHasNoErrors();

        $payload = $category->fresh()->payload;
        $this->assertSame(['fragile', 'legacy-unknown-label'], $payload[Category::PAYLOAD_SHIPPING_LABELS]);
        $this->assertSame(['category_id' => 66, 'image' => 'catalog/watches.jpg'], $payload['opencart']);
        $this->assertSame(['retain' => true], $payload['custom']);
        $this->assertFalse($payload[Category::PAYLOAD_SHOW_FILTERS]);
        $this->assertTrue($payload[Category::PAYLOAD_SHOW_PRODUCTS]);
        $this->assertSame('Updated watches', $category->translations()->first()->name);
        $this->assertSame('Updated category SEO', $category->translations()->first()->meta_title);
    }

    public function test_other_storefronts_keep_shipping_controls_validation_and_save(): void
    {
        config(['storefront.home' => 'auto', 'app.locale' => 'en']);
        app()->setLocale('en');
        $component = Livewire::actingAs($this->makeAdminUser())->test(CategoryForm::class)
            ->assertSee('Pravila dostave kategorije')
            ->assertSee('wire:model="form.shipping_labels"', false)
            ->set('form.code', 'shipping-watches')->set('form.name', 'Watches')->set('form.slug', 'watches')
            ->set('form.shipping_labels', ['invalid-shipping-label'])
            ->call('save')->assertHasErrors(['form.shipping_labels.0']);

        $this->assertSame(0, Category::query()->count());
        $component->set('form.shipping_labels', ['fragile'])->call('save')->assertHasNoErrors();
        $this->assertSame(['fragile'], Category::query()->first()->payload[Category::PAYLOAD_SHIPPING_LABELS]);
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
