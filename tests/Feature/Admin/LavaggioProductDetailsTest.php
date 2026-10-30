<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form;
use App\Livewire\Admin\Catalog\Product\PromotionManager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioProductDetailsTest extends TestCase
{
    use RefreshDatabase;

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
