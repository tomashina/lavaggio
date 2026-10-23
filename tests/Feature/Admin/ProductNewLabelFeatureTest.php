<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Support\ProductNewLabel;
use App\Support\ProductPopularityLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductNewLabelFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_product_editor_defaults_to_unmarked_even_for_a_newly_created_product(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(ProductForm::class)
            ->assertSet('form.is_new', false)
            ->assertSet('form.is_popular', false)
            ->assertSee('Novo u ponudi')
            ->assertSee('Popularno')
            ->assertSee('Uključi proizvod u widgete koji koriste oznaku Novo.')
            ->set('form.locale', 'en')->set('form.code', 'new-unmarked')
            ->set('form.name', 'Newly created watch')->set('form.slug', 'new-unmarked')
            ->set('form.base_price', 100)->set('form.stock_qty', 1)
            ->call('save')->assertHasNoErrors();

        $product = Product::where('code', 'new-unmarked')->sole();
        $component->assertRedirect(route('admin.products.edit', ['product' => $product->id, 'locale' => 'en']));
        $this->assertFalse(ProductNewLabel::isNew($product));
        $this->assertFalse(ProductPopularityLabel::isPopular($product));
        $this->assertSame(0, ProductNewLabel::scope(Product::query())->count());
        $this->assertSame(0, ProductPopularityLabel::scope(Product::query())->count());
    }

    public function test_editor_can_enable_and_disable_new_without_losing_imported_or_other_storefront_data(): void
    {
        $payload = [
            'opencart' => ['model' => 'CW-TEST', 'image' => 'catalog/cluse-test.jpg', 'date_added' => '2020-01-01 10:00:00'],
            'watchline_anchor_price' => ['gross_price' => 120, 'currency_code' => 'EUR'],
            'storefront' => ['custom_label' => 'Keep this label'],
        ];
        $product = $this->product('imported-watch', $payload);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('form.is_new', false)->set('form.is_new', true)
            ->call('save')->assertHasNoErrors();

        $product->refresh();
        $this->assertTrue(ProductNewLabel::isNew($product));
        $this->assertSame($payload['opencart'], $product->payload['opencart']);
        $this->assertSame($payload['watchline_anchor_price'], $product->payload['watchline_anchor_price']);
        $this->assertSame('Keep this label', $product->payload['storefront']['custom_label']);

        Livewire::actingAs($user)->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('form.is_new', true)->set('form.is_new', false)
            ->set('form.payload_text', '{"storefront":{"extra":"New setting"}}')
            ->call('save')->assertHasNoErrors();

        $product->refresh();
        $this->assertFalse(ProductNewLabel::isNew($product));
        $this->assertSame(false, $product->payload['storefront']['is_new']);
        $this->assertSame('New setting', $product->payload['storefront']['extra']);
        $this->assertSame('Keep this label', $product->payload['storefront']['custom_label']);
        $this->assertSame($payload['opencart'], $product->payload['opencart']);
        $this->assertSame($payload['watchline_anchor_price'], $product->payload['watchline_anchor_price']);
    }

    public function test_sql_selection_uses_only_the_manual_flag_and_ignores_product_dates(): void
    {
        $marked = $this->product('older-marked', ['storefront' => ['is_new' => true], 'opencart' => ['date_added' => '2020-01-01 10:00:00']]);
        $unmarked = $this->product('recent-unmarked', ['storefront' => ['is_popular' => true], 'opencart' => ['date_added' => now()->toDateTimeString()]]);
        $disabled = $this->product('recent-disabled', ['storefront' => ['is_new' => false, 'is_popular' => false], 'opencart' => ['date_added' => now()->toDateTimeString()]]);

        $this->assertTrue(ProductNewLabel::isNew($marked));
        $this->assertFalse(ProductNewLabel::isNew($unmarked));
        $this->assertFalse(ProductNewLabel::isNew($disabled));
        $this->assertSame([$marked->id], ProductNewLabel::scope(Product::query())->pluck('id')->all());
        $this->assertFalse(ProductPopularityLabel::isPopular($marked));
        $this->assertTrue(ProductPopularityLabel::isPopular($unmarked));
        $this->assertFalse(ProductPopularityLabel::isPopular($disabled));
        $this->assertSame([$unmarked->id], ProductPopularityLabel::scope(Product::query())->pluck('id')->all());
    }

    public function test_popular_and_new_checkboxes_are_independent_and_store_normalized_booleans(): void
    {
        $product = $this->product('independent-labels', [
            'opencart' => ['model' => 'KEEP-MODEL', 'image' => 'catalog/keep-image.jpg'],
            'storefront' => ['is_new' => true, 'is_popular' => false, 'custom_label' => 'Keep this label'],
        ]);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('form.is_new', true)->assertSet('form.is_popular', false)
            ->set('form.is_new', '0')->set('form.is_popular', '1')
            ->call('save')->assertHasNoErrors();

        $product->refresh();
        $this->assertSame(false, $product->payload['storefront']['is_new']);
        $this->assertSame(true, $product->payload['storefront']['is_popular']);
        $this->assertFalse(ProductNewLabel::isNew($product));
        $this->assertTrue(ProductPopularityLabel::isPopular($product));

        Livewire::actingAs($user)->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('form.is_new', false)->assertSet('form.is_popular', true)
            ->set('form.is_new', '1')->set('form.is_popular', '0')
            ->call('save')->assertHasNoErrors();

        $product->refresh();
        $this->assertSame(true, $product->payload['storefront']['is_new']);
        $this->assertSame(false, $product->payload['storefront']['is_popular']);
        $this->assertTrue(ProductNewLabel::isNew($product));
        $this->assertFalse(ProductPopularityLabel::isPopular($product));
        $this->assertSame('KEEP-MODEL', $product->payload['opencart']['model']);
        $this->assertSame('catalog/keep-image.jpg', $product->payload['opencart']['image']);
        $this->assertSame('Keep this label', $product->payload['storefront']['custom_label']);
    }

    private function product(string $code, array $payload): Product
    {
        $product = Product::create(['code' => $code, 'sku' => $code, 'is_active' => true, 'base_price' => 100, 'stock_qty' => 1, 'payload' => $payload]);
        $product->translations()->create(['locale' => 'en', 'name' => $code, 'slug' => $code]);

        return $product;
    }
}
