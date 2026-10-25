<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Livewire\Admin\Catalog\Product\Manager as ProductManager;
use App\Livewire\Admin\Catalog\Product\OptionValuesManager;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductPackage;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineProductEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'app.locale' => 'en']);
        app()->setLocale('en');
        foreach (['catalog_use_attributes', 'catalog_use_options', 'catalog_use_manufacturers'] as $flag) {
            app(SystemSettingsService::class)->put($flag, true);
        }
    }

    public function test_watchline_editor_and_list_hide_irrelevant_tools_but_other_stores_keep_them(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $component = Livewire::actingAs($admin)->test(ProductForm::class, ['productId' => $product->id])
            ->assertDontSee('Energetske oznake')
            ->assertDontSee('Logistika')
            ->assertDontSee('B2B cijene')
            ->assertSee('Bez porezne klase')
            ->assertSee('Opcije');

        foreach (['energy', 'logistics', 'b2b'] as $tab) {
            $component->call('setTab', $tab)->assertSet('activeTab', 'content')
                ->set('activeTab', $tab)->assertSet('activeTab', 'content');
        }
        $component->call('setTab', 'catalog')->assertDontSee(__('Manage Option Values'));
        Livewire::actingAs($admin)->test(ProductManager::class)
            ->assertDontSeeHtml(route('admin.products.options', ['product' => $product->id, 'locale' => 'en']));

        config(['storefront.home' => 'auto']);
        Livewire::actingAs($admin)->test(ProductForm::class, ['productId' => $product->id])
            ->assertSee('Energetske oznake')->assertSee('Logistika')->assertSee('B2B cijene')
            ->call('setTab', 'logistics')->assertSet('activeTab', 'logistics');
        Livewire::actingAs($admin)->test(ProductManager::class)
            ->assertSeeHtml(route('admin.products.options', ['product' => $product->id, 'locale' => 'en']));
    }

    public function test_watchline_options_tab_uses_native_independent_editor_and_product_save_preserves_variants(): void
    {
        $admin = $this->admin();
        $product = $this->product();
        $product->translations()->create(['locale' => 'hr', 'name' => 'Sat s opcijama', 'slug' => 'sat-s-opcijama']);
        $color = $this->option('color');
        $size = $this->option('size');
        $black = $this->optionValue($color, 'black');
        $small = $this->optionValue($size, 'small');
        $product->options()->attach([
            $color->id => ['sort_order' => 7, 'is_required' => true],
            $size->id => ['sort_order' => 12, 'is_required' => false],
        ]);
        $variant = $product->optionValues()->create([
            'option_value_id' => $small->id, 'parent_option_value_id' => $black->id,
            'mode' => 'linked', 'sku' => 'WATCH-BLACK-S', 'stock_qty' => 3, 'price_override' => 115.5,
            'is_active' => true, 'combination_hash' => hash('sha256', 'linked-watch'),
            'payload' => ['source' => 'original-import', 'dependency' => 'retain'],
        ]);
        $assignments = DB::table('catalog_option_product')->orderBy('option_id')->get()->toArray();
        $variantBefore = $variant->fresh()->getRawOriginal();

        $editor = Livewire::actingAs($admin)->test(ProductForm::class, ['productId' => $product->id])
            ->call('setTab', 'options')->assertSet('activeTab', 'options')
            ->assertSeeLivewire(OptionValuesManager::class)
            ->assertDontSee(__('Update Product'));
        // The parent form is absent in this tab; only the native manager owns its submit.
        $this->assertSame(1, substr_count($editor->html(), '<form wire:submit="save"'));
        $this->assertSame($variantBefore, $variant->fresh()->getRawOriginal());
        $this->assertEquals($assignments, DB::table('catalog_option_product')->orderBy('option_id')->get()->toArray());

        Livewire::actingAs($admin)->test(OptionValuesManager::class, ['productId' => $product->id, 'locale' => 'hr'])
            ->assertSet('locale', 'hr')->assertSet('productName', 'Sat s opcijama')
            ->assertSet('mode', 'linked')->assertSet('rows.0.id', $variant->id)
            ->assertSet('rows.0.parent_option_value_id', $black->id)
            ->set('rows.0.stock_qty', 6)->set('rows.0.price_override', '109.90')
            ->call('save')->assertHasNoErrors()->assertSet('rows.0.id', $variant->id);
        $updatedVariant = $variant->fresh();
        $this->assertSame(6, $updatedVariant->stock_qty);
        $this->assertSame(109.9, (float) $updatedVariant->price_override);
        $this->assertSame($small->id, $updatedVariant->option_value_id);
        $this->assertSame($black->id, $updatedVariant->parent_option_value_id);
        $this->assertSame(['source' => 'original-import', 'dependency' => 'retain'], $updatedVariant->payload);
        $this->assertSame(100.0, (float) $product->fresh()->base_price);
        $this->assertSame(5, $product->fresh()->stock_qty);

        $variantAfterManagement = $updatedVariant->getRawOriginal();
        $editor->call('setTab', 'content')->set('form.name', 'Updated watch with options')
            ->set('form.base_price', 125)->call('save')->assertHasNoErrors();
        $this->assertSame($variantAfterManagement, $variant->fresh()->getRawOriginal());
        $this->assertEquals($assignments, DB::table('catalog_option_product')->orderBy('option_id')->get()->toArray());
        $this->assertSame(125.0, (float) $product->fresh()->base_price);
    }

    public function test_options_tab_requires_feature_and_new_product_must_be_saved_first(): void
    {
        $admin = $this->admin();
        Livewire::actingAs($admin)->test(ProductForm::class)
            ->call('setTab', 'options')->assertSet('activeTab', 'options')
            ->assertSee('Najprije spremite artikl kako biste dodali opcije.')
            ->assertDontSeeLivewire(OptionValuesManager::class);

        app(SystemSettingsService::class)->put('catalog_use_options', false);
        Livewire::actingAs($admin)->test(ProductForm::class)
            ->assertDontSeeHtml("wire:click=\"setTab('options')\"")
            ->call('setTab', 'options')->assertSet('activeTab', 'content')
            ->set('activeTab', 'options')->assertSet('activeTab', 'content');
    }

    public function test_visible_edits_preserve_all_hidden_product_data_and_unchanged_assignments(): void
    {
        $admin = $this->admin();
        $this->tax();
        $payload = [
            'source' => 'herrera-opencart',
            'opencart' => ['image' => 'catalog/GUESS/front.jpg', 'gallery_images' => ['catalog/GUESS/side.jpg', 'catalog/GUESS/back.jpg']],
            'storefront' => ['is_new' => false, 'is_popular' => false, 'custom' => 'retain'],
            'original' => ['product_id' => 99],
        ];
        $product = $this->product([
            'barcode' => '3851234567890', 'unit_of_measure' => 'box',
            'minimum_order_quantity' => 4, 'order_quantity_step' => 2,
            'weight_kg' => 1.234, 'length_cm' => 12.34, 'width_cm' => 8.75, 'height_cm' => 4.25,
            'shipping_labels' => ['fragile', 'no_parcel_locker'], 'energy_label_required' => true,
            'energy_efficiency_class' => 'D', 'energy_efficiency_scale' => 'A-G',
            'eprel_registration_number' => '123456', 'eprel_product_group' => 'electronicdisplays',
            'eprel_lookup_product_group' => 'electronicdisplays', 'eprel_energy_label_image' => 'D.svg',
            'energy_label_url' => 'https://example.test/label.pdf',
            'product_information_sheet_url' => 'https://example.test/sheet.pdf',
            'energy_data_synced_at' => now()->subDay(), 'supplier_stock_qty' => 17,
            'technical_specifications' => [['label' => 'Mehanizam', 'values' => ['Quartz']]],
            'payload' => $payload,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Izvorni sat', 'slug' => 'izvorni-sat', 'payload' => ['original' => 'hr']]);
        $first = $this->category('first');
        $second = $this->category('second');
        $product->categories()->attach([$first->id => ['sort_order' => 12, 'is_primary' => false], $second->id => ['sort_order' => 30, 'is_primary' => true]]);
        $attribute = $this->attribute('quartz');
        $product->attributes()->attach($attribute, ['sort_order' => 42]);
        $package = ProductPackage::query()->create([
            'product_id' => $product->id, 'code' => 'ORIGINAL-BOX', 'name' => 'Original package',
            'quantity' => 4, 'is_active' => true, 'sort_order' => 15, 'payload' => ['original' => true],
        ]);
        $group = CustomerGroup::query()->create(['code' => 'wholesale', 'name' => 'Wholesale', 'is_active' => true]);
        $price = ProductGroupPrice::query()->create([
            'product_id' => $product->id, 'customer_group_id' => $group->id, 'product_package_id' => $package->id,
            'minimum_quantity' => 4, 'price' => 75.1234, 'currency_code' => 'EUR', 'is_active' => true,
            'payload' => ['original' => true],
        ]);
        $declaration = ProductEnergyDeclaration::query()->create([
            'product_id' => $product->id, 'context_code' => 'original', 'energy_class' => 'D',
            'scale_min' => 'A', 'scale_max' => 'G', 'source' => ProductEnergyDeclaration::SOURCE_MANUAL,
            'is_primary' => true, 'payload' => ['original' => true],
        ]);
        $hiddenFields = ['barcode', 'unit_of_measure', 'minimum_order_quantity', 'order_quantity_step',
            'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'shipping_labels', 'energy_label_required',
            'energy_efficiency_class', 'energy_efficiency_scale', 'eprel_registration_number', 'eprel_product_group',
            'eprel_lookup_product_group', 'eprel_energy_label_image', 'energy_label_url', 'product_information_sheet_url',
            'energy_data_synced_at', 'supplier_stock_qty', 'technical_specifications', 'tax_rate_id'];
        $before = $product->fresh()->only($hiddenFields);
        $packageBefore = $package->fresh()->getRawOriginal();
        $priceBefore = $price->fresh()->getRawOriginal();
        $declarationBefore = $declaration->fresh()->getRawOriginal();
        $categoryBefore = DB::table('category_product')->orderBy('category_id')->get()->toArray();
        $attributesBefore = DB::table('catalog_attribute_product')->get()->toArray();

        Livewire::withQueryParams(['locale' => 'en'])->actingAs($admin)
            ->test(ProductForm::class, ['productId' => $product->id])
            ->assertSet('form.tax_rate_id', null)
            ->set('form.name', 'Updated watch')->set('form.slug', 'updated-watch')
            ->set('form.description', '<p>Updated description</p>')
            ->set('form.excerpt', 'Updated summary')->set('form.meta_title', 'Updated SEO title')
            ->set('form.meta_description', 'Updated SEO description')
            ->set('form.base_price', 149.5)->set('form.stock_qty', 9)
            ->set('form.is_new', true)->set('form.is_popular', true)
            // Hidden stale or malformed values cannot block saving or overwrite source data.
            ->set('form.minimum_order_quantity', 0)->set('form.weight_kg', -5)
            ->set('form.energy_label_required', false)->set('form.shipping_labels', [])
            ->set('packages', [])->set('groupPrices', [])->set('energyDeclarations', [['context_code' => 'invalid!']])
            ->call('save')->assertHasNoErrors()
            ->assertRedirect(route('admin.products.edit', ['product' => $product->id, 'locale' => 'en']));

        $saved = $product->fresh();
        $this->assertEquals($before, $saved->only($hiddenFields));
        $this->assertSame($packageBefore, $package->fresh()->getRawOriginal());
        $this->assertSame($priceBefore, $price->fresh()->getRawOriginal());
        $this->assertSame($declarationBefore, $declaration->fresh()->getRawOriginal());
        $this->assertEquals($categoryBefore, DB::table('category_product')->orderBy('category_id')->get()->toArray());
        $this->assertEquals($attributesBefore, DB::table('catalog_attribute_product')->get()->toArray());
        $payload['storefront']['is_new'] = true;
        $payload['storefront']['is_popular'] = true;
        $this->assertSame($payload, $saved->payload);
        $this->assertSame(149.5, (float) $saved->base_price);
        $this->assertSame(9, $saved->stock_qty);
        $this->assertSame($admin->id, $saved->updated_by);
        $this->assertDatabaseHas('product_translations', [
            'product_id' => $product->id, 'locale' => 'en', 'name' => 'Updated watch', 'slug' => 'updated-watch',
            'excerpt' => 'Updated summary', 'description' => '<p>Updated description</p>',
            'meta_title' => 'Updated SEO title', 'meta_description' => 'Updated SEO description',
        ]);
        $this->assertSame(['original' => 'hr'], $saved->translations()->where('locale', 'hr')->firstOrFail()->payload);
    }

    public function test_visible_category_attribute_brand_tax_and_state_changes_still_save(): void
    {
        $product = $this->product();
        $oldCategory = $this->category('old');
        $newCategory = $this->category('new');
        $product->categories()->attach($oldCategory);
        $oldAttribute = $this->attribute('quartz');
        $newAttribute = $this->attribute('automatic');
        $product->attributes()->attach($oldAttribute);
        $maker = Manufacturer::query()->create(['code' => 'guess', 'is_active' => true]);
        $tax = $this->tax();

        Livewire::actingAs($this->admin())->test(ProductForm::class, ['productId' => $product->id])
            ->set('form.category_ids', [$newCategory->id])
            ->set('attributeSelections.movement', (string) $newAttribute->id)
            ->set('form.manufacturer_id', $maker->id)->set('form.tax_rate_id', $tax->id)
            ->set('form.is_active', false)->call('save')->assertHasNoErrors();

        $this->assertSame([$newCategory->id], $product->categories()->pluck('categories.id')->all());
        $this->assertSame([$newAttribute->id], $product->attributes()->pluck('catalog_attributes.id')->all());
        $this->assertDatabaseHas('category_product', ['product_id' => $product->id, 'category_id' => $newCategory->id, 'is_primary' => true]);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false, 'manufacturer_id' => $maker->id, 'tax_rate_id' => $tax->id]);
    }

    public function test_new_watchline_product_uses_native_hidden_defaults_and_validates_visible_fields(): void
    {
        $component = Livewire::actingAs($this->admin())->test(ProductForm::class)
            ->set('form.code', 'new-watch')->set('form.name', 'New watch')->set('form.slug', 'new-watch')
            ->set('form.base_price', -1)->call('save')->assertHasErrors(['form.base_price']);
        $this->assertDatabaseCount('products', 0);
        $component->set('form.base_price', 50)->set('form.stock_qty', 3)
            ->set('form.unit_of_measure', 'invalid')->set('form.minimum_order_quantity', 0)
            ->set('form.weight_kg', -1)->set('form.energy_label_required', true)
            ->set('packages', [['code' => 'invalid']])->set('groupPrices', [['price' => -5]])
            ->set('energyDeclarations', [['context_code' => 'invalid!']])
            ->call('save')->assertHasNoErrors();

        $product = Product::query()->where('code', 'new-watch')->firstOrFail();
        $this->assertSame('pcs', $product->unit_of_measure);
        $this->assertSame(1, $product->minimum_order_quantity);
        $this->assertSame(1, $product->order_quantity_step);
        $this->assertNull($product->weight_kg);
        $this->assertFalse($product->energy_label_required);
        $this->assertDatabaseCount('catalog_product_packages', 0);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
        $this->assertDatabaseCount('product_energy_declarations', 0);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function product(array $attributes = []): Product
    {
        $product = Product::query()->create(array_replace([
            'code' => 'source-watch', 'sku' => 'SOURCE-WATCH', 'is_active' => true,
            'base_price' => 100, 'stock_qty' => 5, 'tax_rate_id' => null,
        ], $attributes));
        $product->translations()->create(['locale' => 'en', 'name' => 'Original watch', 'slug' => 'original-watch']);

        return $product;
    }

    private function category(string $code): Category
    {
        $category = new Category(['scope' => Category::SCOPE_CATALOG, 'code' => $code, 'is_active' => true]);
        $category->saveAsRoot();
        $category->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'en', 'name' => $code, 'slug' => $code]);

        return $category;
    }

    private function attribute(string $code): Attribute
    {
        $attribute = Attribute::query()->create([
            'code' => $code, 'group_code' => 'movement', 'type' => Attribute::TYPE_SELECT, 'is_active' => true,
        ]);
        $attribute->translations()->create(['locale' => 'en', 'name' => $code, 'group_name' => 'Movement', 'slug' => $code]);

        return $attribute;
    }

    private function tax(): TaxRate
    {
        return TaxRate::query()->create([
            'code' => 'default-vat', 'name' => 'PDV 25%', 'rate_type' => 'percent',
            'rate' => 25, 'is_default' => true, 'is_active' => true,
        ]);
    }

    private function option(string $code): Option
    {
        $option = Option::query()->create(['code' => $code, 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $option->translations()->create(['locale' => 'en', 'name' => $code, 'slug' => $code]);

        return $option;
    }

    private function optionValue(Option $option, string $code): OptionValue
    {
        $value = $option->values()->create(['code' => $code, 'is_active' => true]);
        $value->translations()->create(['locale' => 'en', 'name' => $code, 'slug' => $code]);

        return $value;
    }
}
