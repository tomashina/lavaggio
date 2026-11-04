<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form;
use App\Livewire\Admin\Catalog\Product\Manager as ProductManager;
use App\Livewire\Admin\Catalog\Product\OptionValuesManager;
use App\Livewire\Admin\Catalog\Product\PromotionManager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_lavaggio_create_and_edit_forms_hide_and_reject_unneeded_tabs(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $user = User::factory()->create();
        $product = Product::query()->create(['code' => 'LAV-SIMPLE-EDITOR', 'base_price' => 20]);

        foreach ([[], ['productId' => $product->id]] as $parameters) {
            $editor = Livewire::actingAs($user)->test(Form::class, $parameters)
                ->assertDontSee('Energetske oznake')
                ->assertDontSee('Logistika')
                ->assertDontSee('B2B cijene')
                ->assertSeeHtml('wire:model="form.barcode"');

            foreach (['energy', 'logistics', 'b2b'] as $tab) {
                $editor->call('setTab', $tab)->assertSet('activeTab', 'content')
                    ->set('activeTab', $tab)->assertSet('activeTab', 'content');
            }
        }
    }

    public function test_lavaggio_product_list_omits_the_separate_option_values_action_while_other_stores_keep_it(): void
    {
        config(['storefront.home' => 'lavaggio']);
        app(SystemSettingsService::class)->put('catalog_use_options', true);
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);
        $product = Product::query()->create(['code' => 'LAV-OPTION-ACTIONS', 'base_price' => 20, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Proizvod s opcijama', 'slug' => 'proizvod-s-opcijama']);
        $optionsUrl = route('admin.products.options', ['product' => $product->id, 'locale' => 'hr']);
        $editUrl = route('admin.products.edit', ['product' => $product->id, 'locale' => 'hr']);

        Livewire::withQueryParams(['locale' => 'hr'])->actingAs($user)->test(ProductManager::class)
            ->assertSeeHtml($editUrl)->assertDontSeeHtml($optionsUrl);

        config(['storefront.home' => 'auto']);
        Livewire::withQueryParams(['locale' => 'hr'])->actingAs($user)->test(ProductManager::class)
            ->assertSeeHtml($optionsUrl);
    }

    public function test_lavaggio_options_tab_can_assign_groups_and_save_values_without_product_edits_removing_them(): void
    {
        config(['storefront.home' => 'lavaggio']);
        app(SystemSettingsService::class)->put('catalog_use_options', true);
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);
        $product = Product::query()->create(['code' => 'LAV-OPTIONS-EDITOR', 'base_price' => 20, 'stock_qty' => 10]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Deterdžent', 'slug' => 'deterdzent']);
        $option = Option::query()->create(['code' => 'fragrance', 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $option->translations()->create(['locale' => 'hr', 'name' => 'Miris', 'slug' => 'miris']);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'lavender', 'is_active' => true]);
        $value->translations()->create(['locale' => 'hr', 'name' => 'Lavanda', 'slug' => 'lavanda']);

        $editor = Livewire::withQueryParams(['locale' => 'hr'])->actingAs($user)
            ->test(Form::class, ['productId' => $product->id])
            ->assertSeeHtml("wire:click=\"setTab('options')\"")
            ->call('setTab', 'catalog')->assertDontSee(__('Manage Option Values'))
            ->call('setTab', 'options')->assertSet('activeTab', 'options')
            ->assertSeeLivewire(OptionValuesManager::class)
            ->assertDontSee(__('Update Product'));

        Livewire::actingAs($user)->test(OptionValuesManager::class, ['productId' => $product->id, 'locale' => 'hr'])
            ->assertSee('Miris')
            ->set('selectedOptionIds', [$option->id])->call('saveOptionGroups')->assertHasNoErrors()
            ->call('addRow')->assertSet('rows.0.option_value_id', $value->id)
            ->set('rows.0.sku', 'DETERGENT-LAVENDER')->set('rows.0.stock_qty', 8)
            ->set('rows.0.price_override', '22.50')
            ->call('save')->assertHasNoErrors();

        $this->assertTrue($product->options()->whereKey($option->id)->exists());
        $variant = $product->optionValues()->sole();
        $this->assertSame($value->id, $variant->option_value_id);
        $this->assertSame('DETERGENT-LAVENDER', $variant->sku);
        $this->assertSame(8, $variant->stock_qty);
        $this->assertSame(22.5, (float) $variant->price_override);
        $before = $variant->getRawOriginal();

        $editor->call('setTab', 'content')->set('form.name', 'Deterdžent s mirisom')
            ->set('form.base_price', 21)->call('save')->assertHasNoErrors();
        $this->assertSame($before, $variant->fresh()->getRawOriginal());
        $this->assertTrue($product->options()->whereKey($option->id)->exists());
        Livewire::actingAs($user)->test(OptionValuesManager::class, ['productId' => $product->id, 'locale' => 'hr'])
            ->assertSet('rows.0.id', $variant->id)
            ->assertSet('rows.0.sku', 'DETERGENT-LAVENDER')
            ->assertSet('rows.0.stock_qty', 8);
    }

    public function test_lavaggio_options_tab_requires_enabled_options_and_a_saved_product(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $settings = app(SystemSettingsService::class);
        $settings->put('catalog_use_options', true);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Form::class)
            ->call('setTab', 'options')->assertSet('activeTab', 'options')
            ->assertSee('Najprije spremite artikl kako biste dodali opcije.')
            ->assertDontSeeLivewire(OptionValuesManager::class);

        $settings->put('catalog_use_options', false);
        Livewire::actingAs($user)->test(Form::class)
            ->assertDontSeeHtml("wire:click=\"setTab('options')\"")
            ->call('setTab', 'options')->assertSet('activeTab', 'content')
            ->set('activeTab', 'options')->assertSet('activeTab', 'content');
    }

    public function test_lavaggio_content_edits_preserve_hidden_logistics_prices_and_energy_data(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $user = User::factory()->create();
        $product = Product::query()->create([
            'code' => 'LAV-HIDDEN-DATA', 'barcode' => '3851234567890', 'base_price' => 20,
            'unit_of_measure' => 'box', 'minimum_order_quantity' => 6, 'order_quantity_step' => 3,
            'weight_kg' => 2.345, 'length_cm' => 12.34, 'width_cm' => 8.75, 'height_cm' => 4.25,
            'shipping_labels' => ['fragile', 'no_parcel_locker'], 'energy_label_required' => true,
            'energy_efficiency_class' => 'D', 'energy_efficiency_scale' => 'A-G',
            'eprel_registration_number' => '123456', 'eprel_product_group' => 'electronicdisplays',
            'eprel_lookup_product_group' => 'electronicdisplays', 'eprel_energy_label_image' => 'D.svg',
            'energy_label_url' => 'https://example.test/label.pdf',
            'product_information_sheet_url' => 'https://example.test/sheet.pdf',
            'energy_data_synced_at' => now()->subDay(),
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Izvorni proizvod', 'slug' => 'izvorni-proizvod']);
        $package = $product->packages()->create([
            'code' => 'ORIGINAL-BOX', 'name' => 'Izvorno pakiranje', 'quantity' => 6,
            'is_default' => true, 'is_active' => true, 'sort_order' => 15, 'payload' => ['original' => true],
        ]);
        $group = CustomerGroup::query()->create(['code' => 'wholesale', 'name' => 'Wholesale', 'is_active' => true]);
        $price = $product->groupPrices()->create([
            'customer_group_id' => $group->id, 'product_package_id' => $package->id,
            'minimum_quantity' => 6, 'price' => 15.1234, 'currency_code' => 'EUR', 'is_active' => true,
            'payload' => ['original' => true],
        ]);
        $declaration = $product->energyDeclarations()->create([
            'context_code' => 'original', 'energy_class' => 'D', 'scale_min' => 'A', 'scale_max' => 'G',
            'source' => ProductEnergyDeclaration::SOURCE_MANUAL, 'is_primary' => true,
            'payload' => ['original' => true], 'synced_at' => now()->subDay(),
        ]);
        $hiddenFields = ['unit_of_measure', 'minimum_order_quantity', 'order_quantity_step',
            'weight_kg', 'length_cm', 'width_cm', 'height_cm', 'shipping_labels', 'energy_label_required',
            'energy_efficiency_class', 'energy_efficiency_scale', 'eprel_registration_number', 'eprel_product_group',
            'eprel_lookup_product_group', 'eprel_energy_label_image', 'energy_label_url',
            'product_information_sheet_url', 'energy_data_synced_at'];
        $before = array_intersect_key($product->fresh()->getRawOriginal(), array_flip($hiddenFields));
        $packageBefore = $package->fresh()->getRawOriginal();
        $priceBefore = $price->fresh()->getRawOriginal();
        $declarationBefore = $declaration->fresh()->getRawOriginal();

        // Empty arrays must not delete existing rows, and malformed hidden rows must not block visible edits.
        foreach ([[], [['invalid' => true]]] as $hiddenRows) {
            Livewire::withQueryParams(['locale' => 'hr'])->actingAs($user)
                ->test(Form::class, ['productId' => $product->id])
                ->set('form.name', 'Ažurirani proizvod')->set('form.base_price', 24.95)
                ->set('form.barcode', '3851234567891')
                ->set('form.unit_of_measure', 'invalid')->set('form.minimum_order_quantity', 0)
                ->set('form.order_quantity_step', 0)->set('form.weight_kg', -5)
                ->set('form.length_cm', -5)->set('form.width_cm', -5)->set('form.height_cm', -5)
                ->set('form.shipping_labels', ['invalid'])->set('form.energy_label_required', 'invalid')
                ->set('packages', $hiddenRows)->set('groupPrices', $hiddenRows)->set('energyDeclarations', $hiddenRows)
                ->call('save')->assertHasNoErrors();

            $saved = $product->fresh();
            $this->assertSame($before, array_intersect_key($saved->getRawOriginal(), array_flip($hiddenFields)));
            $this->assertSame($packageBefore, $package->fresh()->getRawOriginal());
            $this->assertSame($priceBefore, $price->fresh()->getRawOriginal());
            $this->assertSame($declarationBefore, $declaration->fresh()->getRawOriginal());
            $this->assertSame(1, $saved->packages()->count());
            $this->assertSame(1, $saved->groupPrices()->count());
            $this->assertSame(1, $saved->energyDeclarations()->count());
            $this->assertSame('3851234567891', $saved->barcode);
            $this->assertSame('24.95', $saved->base_price);
            $this->assertDatabaseHas('product_translations', [
                'product_id' => $product->id, 'locale' => 'hr', 'name' => 'Ažurirani proizvod',
            ]);
        }
    }

    public function test_new_lavaggio_products_use_native_hidden_defaults_and_save_visible_barcode(): void
    {
        config(['storefront.home' => 'lavaggio']);

        Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('form.code', 'LAV-DEFAULTS')->set('form.locale', 'hr')
            ->set('form.name', 'Novi proizvod')->set('form.slug', 'novi-proizvod')
            ->set('form.base_price', 12.95)->set('form.barcode', '3851234567892')
            ->set('form.unit_of_measure', 'box')->set('form.minimum_order_quantity', 5)
            ->set('form.order_quantity_step', 5)->set('form.weight_kg', 10)
            ->set('form.shipping_labels', ['fragile'])->set('form.energy_label_required', true)
            ->call('save')->assertHasNoErrors();

        $product = Product::query()->where('code', 'LAV-DEFAULTS')->firstOrFail();
        $this->assertSame('3851234567892', $product->barcode);
        $this->assertSame('12.95', $product->base_price);
        $this->assertSame('pcs', $product->unit_of_measure);
        $this->assertSame(1, $product->minimum_order_quantity);
        $this->assertSame(1, $product->order_quantity_step);
        $this->assertNull($product->weight_kg);
        $this->assertNull($product->shipping_labels);
        $this->assertFalse($product->energy_label_required);
        $this->assertFalse($product->packages()->exists());
        $this->assertFalse($product->groupPrices()->exists());
        $this->assertFalse($product->energyDeclarations()->exists());
    }

    public function test_laundry_product_details_can_be_saved_and_reopened_in_admin(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Form::class)
            ->set('form.code', 'LAV-DETAILS')
            ->set('form.sku', 'LAV-DETAILS')
            ->set('form.barcode', '4000000000017')
            ->set('form.locale', 'hr')
            ->set('form.name', 'Profesionalni deterdžent')
            ->set('form.slug', 'profesionalni-deterdzent')
            ->set('form.base_price', 19.95)
            ->set('form.is_professional', true)
            ->set('form.content_quantity', 2.7)
            ->set('form.content_unit', 'l')
            ->set('form.listing_price', 24.95)
            ->set('form.listed_at', '2026-09-10')
            ->set('form.delivery_mode', 'supplier')
            ->set('form.supplier_stock_qty', 10)
            ->set('form.ingredients', '5–15 % anionske površinski aktivne tvari.')
            ->set('form.hazard_notes', 'Uzrokuje jako nadraživanje oka.')
            ->set('form.manufacturer_importer', 'Primjer proizvođača, Zagreb, Hrvatska')
            ->set('form.country_of_origin', 'Njemačka')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::query()->where('code', 'LAV-DETAILS')->firstOrFail();
        $this->assertTrue($product->is_professional);
        $this->assertSame('2.700', $product->content_quantity);
        $this->assertSame('24.95', $product->listing_price);
        $this->assertSame('2026-09-10', $product->listed_at->format('Y-m-d'));
        $this->assertSame('supplier', $product->delivery_mode);
        $this->assertSame(10, $product->supplier_stock_qty);
        $this->assertSame('Uzrokuje jako nadraživanje oka.', $product->hazard_notes);

        Livewire::actingAs($user)->test(Form::class, ['productId' => $product->id])
            ->assertSet('form.is_professional', true)
            ->assertSet('form.content_quantity', '2.700')
            ->assertSet('form.content_unit', 'l')
            ->assertSet('form.supplier_stock_qty', 10)
            ->assertSet('form.listed_at', '2026-09-10')
            ->assertSet('form.ingredients', '5–15 % anionske površinski aktivne tvari.')
            ->assertSet('form.manufacturer_importer', 'Primjer proizvođača, Zagreb, Hrvatska')
            ->assertSet('form.country_of_origin', 'Njemačka');
    }

    public function test_unit_pricing_requires_both_quantity_and_valid_unit(): void
    {
        config(['storefront.home' => 'lavaggio']);
        Livewire::actingAs(User::factory()->create())->test(Form::class)
            ->set('form.code', 'LAV-INVALID')
            ->set('form.locale', 'hr')
            ->set('form.name', 'Neispravno pakiranje')
            ->set('form.slug', 'neispravno-pakiranje')
            ->set('form.content_quantity', 2.7)
            ->set('form.content_unit', '')
            ->set('form.delivery_mode', 'unknown')
            ->set('form.supplier_stock_qty', -1)
            ->call('save')
            ->assertHasErrors(['form.content_unit', 'form.delivery_mode', 'form.supplier_stock_qty']);

        $this->assertDatabaseMissing('products', ['code' => 'LAV-INVALID']);
    }

    public function test_lavaggio_product_promotions_can_be_created_from_the_product_editor(): void
    {
        config(['storefront.home' => 'lavaggio']);
        app(SystemSettingsService::class)->put('catalog_use_actions', true);
        $user = User::factory()->create();
        Bouncer::assign('superadmin')->to($user);
        $product = Product::query()->create(['code' => 'LAV-PROMO-EDIT', 'base_price' => 20]);

        Livewire::actingAs($user)->test(Form::class, ['productId' => $product->id])
            ->call('setTab', 'promotion')
            ->assertSet('activeTab', 'promotion')
            ->assertSeeLivewire(PromotionManager::class);

        Livewire::actingAs($user)->test(PromotionManager::class, ['productId' => $product->id])
            ->call('create')
            ->set('form.title', 'Jesenska akcija')
            ->set('form.discount_value', 20)
            ->set('form.starts_at', '2026-10-28T00:00:00')
            ->set('form.ends_at', '2026-11-05T23:59:59')
            ->call('save')
            ->assertHasNoErrors();

        $action = CatalogAction::query()->firstOrFail();
        $this->assertStringStartsWith('lavaggio-product-', $action->code);
        $this->assertSame(20.0, (float) $action->discount_value);
        $this->assertSame('2026-11-05', $action->ends_at->format('Y-m-d'));
    }

    public function test_professional_checkbox_keeps_the_pro_filter_attribute_in_sync(): void
    {
        config(['storefront.home' => 'lavaggio']);
        app(SystemSettingsService::class)->put('catalog_use_attributes', true);
        $user = User::factory()->create();
        $product = Product::query()->create(['code' => 'LAV-PRO-SYNC', 'base_price' => 20]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Proizvod', 'slug' => 'proizvod']);

        // The PRO attribute has not been seeded yet; the editor creates it.
        Livewire::actingAs($user)->test(Form::class, ['productId' => $product->id])
            ->set('form.is_professional', true)->call('save')->assertHasNoErrors();
        $pro = Attribute::query()->where('code', 'lavaggio-pro-pro')->firstOrFail();
        $this->assertTrue($product->fresh()->is_professional);
        $this->assertTrue($product->attributes()->whereKey($pro->id)->exists());

        // Existing PRO selections must be removed when the checkbox is off.
        Livewire::actingAs($user)->test(Form::class, ['productId' => $product->id])
            ->set('form.is_professional', false)->call('save')->assertHasNoErrors();
        $this->assertFalse($product->fresh()->is_professional);
        $this->assertFalse($product->attributes()->whereKey($pro->id)->exists());

        Livewire::actingAs($user)->test(Form::class, ['productId' => $product->id])
            ->set('form.is_professional', true)->call('save')->assertHasNoErrors();
        $this->assertTrue($product->fresh()->is_professional);
        $this->assertTrue($product->attributes()->whereKey($pro->id)->exists());
        $this->assertSame(1, Attribute::query()->where('code', 'lavaggio-pro-pro')->count());
    }

    public function test_unavailable_delivery_requires_zero_local_and_supplier_stock(): void
    {
        config(['storefront.home' => 'lavaggio']);
        $user = User::factory()->create();
        $product = Product::query()->create([
            'code' => 'LAV-UNAVAILABLE', 'base_price' => 20, 'stock_qty' => 5,
            'supplier_stock_qty' => 0, 'delivery_mode' => 'stock',
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Proizvod', 'slug' => 'proizvod']);

        Livewire::actingAs($user)->test(Form::class, ['productId' => $product->id])
            ->set('form.delivery_mode', 'unavailable')->call('save')
            ->assertHasErrors('form.delivery_mode');
        $this->assertSame('stock', $product->fresh()->delivery_mode);
        $this->assertSame(5, $product->fresh()->stock_qty);

        $product->update(['stock_qty' => 0, 'supplier_stock_qty' => 4]);
        Livewire::actingAs($user)->test(Form::class, ['productId' => $product->id])
            ->set('form.delivery_mode', 'unavailable')->call('save')
            ->assertHasErrors('form.delivery_mode');
        $this->assertSame('stock', $product->fresh()->delivery_mode);
        $this->assertSame(4, $product->fresh()->supplier_stock_qty);

        Livewire::actingAs($user)->test(Form::class, ['productId' => $product->id])
            ->set('form.supplier_stock_qty', 0)
            ->set('form.delivery_mode', 'unavailable')->call('save')
            ->assertHasNoErrors();
        $this->assertSame('unavailable', $product->fresh()->delivery_mode);
        $this->assertSame(0, $product->fresh()->supplier_stock_qty);
    }

    public function test_promotion_times_round_trip_between_zagreb_inputs_and_utc_storage(): void
    {
        config(['storefront.home' => 'lavaggio', 'app.timezone' => 'UTC']);
        app(SystemSettingsService::class)->put('catalog_use_actions', true);
        $user = User::factory()->create();
        Bouncer::assign('superadmin')->to($user);
        $product = Product::query()->create(['code' => 'LAV-LOCAL-TIME', 'base_price' => 20]);

        $manager = Livewire::actingAs($user)->test(PromotionManager::class, ['productId' => $product->id])
            ->call('create')->assertSee('Europe/Zagreb')
            ->set('form.title', 'Akcija do lokalne ponoći')
            ->set('form.starts_at', '2026-10-28T12:30:00')
            ->set('form.ends_at', '2026-11-04T23:59')
            ->call('save')->assertHasNoErrors()
            ->assertSee('04.11.2026. 23:59');

        $action = CatalogAction::query()->firstOrFail();
        $this->assertSame('2026-10-28 11:30:00', $action->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-04 22:59:00', $action->ends_at->format('Y-m-d H:i:s'));

        $manager->call('edit', $action->id)
            ->assertSet('form.starts_at', '2026-10-28T12:30:00')
            ->assertSet('form.ends_at', '2026-11-04T23:59:00')
            ->set('form.ends_at', '2026-11-05T23:59:00')
            ->call('save')->assertHasNoErrors()
            ->assertSee('05.11.2026. 23:59');

        $this->assertSame('2026-11-05 22:59:00', $action->fresh()->ends_at->format('Y-m-d H:i:s'));
    }
}
