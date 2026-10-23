<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockTranslation;
use App\Models\User;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineWidgetAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_widget_edit_and_code_change_preserve_template_files(): void
    {
        $block = $this->campaign();
        $originalPath = resource_path('views/front/content-blocks/instances/'.$block->code.'.blade.php');
        $renamedCode = 'watchline-admin-preserved-template-renamed';
        $renamedPath = resource_path('views/front/content-blocks/instances/'.$renamedCode.'.blade.php');
        $original = '<p>Preserve this fixed design fixture.</p>';
        file_put_contents($originalPath, $original);

        try {
            Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
                ->assertSet('form.template_body', '')
                ->assertSet('form.watchline.mens_title', 'Prethodni naslov')
                ->assertSee('Napredne postavke widgeta')
                ->assertSeeHtml('<details class="admin-panel p-4">')
                ->assertDontSee(__('Background CSS'))
                ->set('form.code', $renamedCode)
                ->set('form.watchline.mens_title', 'Odaberite svoj novi sat')
                ->set('form.watchline.mens_image_url', '/image/catalog/SEIKO/ssc939p1_1.jpg')
                ->set('form.template_body', '<p>Unexpected replacement</p>')
                ->set('form.bg_css', 'color:red')
                ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.content.blocks'));

            $block->refresh()->load('translations');
            $translation = $block->translations->firstWhere('locale', 'hr');
            $this->assertSame('Odaberite svoj novi sat', data_get($translation->payload, 'watchline.mens_title'));
            $this->assertArrayNotHasKey('bg_css', $translation->payload);
            $this->assertSame($original, file_get_contents($originalPath));
            $this->assertFileDoesNotExist($renamedPath);
            Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
                ->assertSet('form.watchline.mens_title', 'Odaberite svoj novi sat');
        } finally {
            @unlink($originalPath);
            @unlink($renamedPath);
        }
    }

    public function test_manual_product_order_is_saved_and_automatic_source_allows_no_items(): void
    {
        $first = Product::query()->create(['code' => 'widget-first', 'sku' => 'FIRST', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 1]);
        $second = Product::query()->create(['code' => 'widget-second', 'sku' => 'SECOND', 'is_active' => true, 'base_price' => 20, 'stock_qty' => 1]);

        Livewire::actingAs($this->admin())->test(Form::class)
            ->assertDontSee('Napredne postavke widgeta')
            ->set('form.type', 'watchline_products')->set('form.code', 'watchline-admin-manual-products')
            ->set('form.name', 'Odabrani satovi')->set('form.locale', 'hr')
            ->set('form.selected_item_ids', [$second->id, $first->id])
            ->set('form.items_limit', 8)->call('save')->assertHasNoErrors();

        $block = ContentBlock::query()->where('code', 'watchline-admin-manual-products')->firstOrFail();
        $this->assertSame([$second->id, $first->id], $block->items->pluck('item_id')->all());
        $this->assertSame(8, data_get($block->translations->firstWhere('locale', 'hr')->payload, 'items_limit'));

        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
            ->assertSet('form.selected_item_ids', [$second->id, $first->id])
            ->set('form.watchline.source', 'guess_newest')->set('form.selected_item_ids', [])
            ->call('save')->assertHasNoErrors();

        $this->assertSame(0, $block->fresh()->items()->count());
        $this->assertSame('guess_newest', data_get($block->fresh()->translations->firstWhere('locale', 'hr')->payload, 'watchline.source'));
    }

    public function test_empty_manual_widget_automatically_uses_selected_categories_and_saves_without_individual_products(): void
    {
        $category = $this->filterCategory('watches', 'Satovi');
        $admin = $this->admin();
        $component = Livewire::actingAs($admin)->test(Form::class)
            ->set('form.type', 'watchline_products')->set('form.code', 'watchline-automatic-categories')
            ->set('form.name', 'Satovi iz kategorija')->set('form.locale', 'hr')
            ->assertSet('form.watchline.source', 'manual')
            ->set('form.watchline.category_ids', [(string) $category->id])
            ->assertSet('form.watchline.source', 'filtered')
            ->assertSee('Nije ih potrebno dodavati pojedinačno.')
            ->assertDontSee('Prikazano je najviše 100 rezultata.')
            ->set('form.watchline.source', 'manual')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.content.blocks'));

        $block = ContentBlock::query()->where('code', 'watchline-automatic-categories')->firstOrFail();
        $this->assertSame(0, $block->items()->count());
        $this->assertSame('filtered', data_get($block->translations->firstWhere('locale', 'hr')->payload, 'watchline.source'));
        Livewire::actingAs($admin)->test(Form::class, ['blockId' => $block->id])
            ->assertSet('form.watchline.source', 'filtered')
            ->assertSet('form.watchline.category_ids', [$category->id]);
    }

    public function test_manual_products_are_preserved_until_last_item_is_removed_then_brand_filter_becomes_automatic(): void
    {
        $first = Product::query()->create(['code' => 'manual-first', 'sku' => 'MANUAL-FIRST', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 1]);
        $second = Product::query()->create(['code' => 'manual-second', 'sku' => 'MANUAL-SECOND', 'is_active' => true, 'base_price' => 20, 'stock_qty' => 1]);
        $brand = $this->filterManufacturer('manual-filter-brand', 'Odabrani brand');
        $component = Livewire::actingAs($this->admin())->test(Form::class)
            ->set('form.type', 'watchline_products')->set('form.code', 'watchline-automatic-brand')
            ->set('form.name', 'Odabrani brand')->set('form.locale', 'hr')
            ->set('form.selected_item_ids', [$second->id, $first->id])
            ->set('form.watchline.manufacturer_ids', [(string) $brand->id])
            ->assertSet('form.watchline.source', 'manual')
            ->assertSet('form.selected_item_ids', [$second->id, $first->id])
            ->call('removeSelectedItem', $first->id)->assertSet('form.watchline.source', 'manual')
            ->call('removeSelectedItem', $second->id)->assertSet('form.watchline.source', 'filtered')
            ->assertSet('form.selected_item_ids', [])
            ->call('save')->assertHasNoErrors();

        $block = ContentBlock::query()->where('code', 'watchline-automatic-brand')->firstOrFail();
        $this->assertSame('filtered', data_get($block->translations->firstWhere('locale', 'hr')->payload, 'watchline.source'));
        $this->assertSame([$brand->id], data_get($block->translations->firstWhere('locale', 'hr')->payload, 'watchline.manufacturer_ids'));
    }

    public function test_empty_manual_widget_without_category_or_brand_filters_still_requires_products(): void
    {
        Livewire::actingAs($this->admin())->test(Form::class)
            ->set('form.type', 'watchline_products')->set('form.code', 'watchline-empty-manual')
            ->set('form.name', 'Ručni izbor')->call('save')
            ->assertSet('form.watchline.source', 'manual')->assertHasErrors(['form.selected_item_ids']);

        $this->assertDatabaseMissing('content_blocks', ['code' => 'watchline-empty-manual']);
    }

    public function test_product_picker_limits_results_but_searches_entire_catalog_and_preserves_manual_selection(): void
    {
        $oldest = Product::query()->create(['code' => 'oldest-searchable-watch', 'sku' => 'OLDEST-001', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 1]);
        $oldest->translations()->create(['locale' => 'hr', 'name' => 'Najstariji pretraživi sat', 'slug' => 'oldest-searchable-watch']);
        $selected = Product::query()->create(['code' => 'existing-selected-watch', 'sku' => 'SELECTED', 'is_active' => true, 'base_price' => 20, 'stock_qty' => 1]);
        $selected->translations()->create(['locale' => 'hr', 'name' => 'Prethodno odabran sat', 'slug' => 'existing-selected-watch']);
        for ($index = 1; $index <= 101; $index++) {
            Product::query()->create(['code' => 'recent-picker-watch-'.$index, 'sku' => 'RECENT-'.$index, 'is_active' => true, 'base_price' => 30, 'stock_qty' => 1]);
        }
        $category = $this->filterCategory('watches', 'Satovi');
        $admin = $this->admin();
        $component = Livewire::actingAs($admin)->test(Form::class)
            ->set('form.type', 'watchline_products')->set('form.locale', 'hr')
            ->set('form.code', 'watchline-bounded-picker')->set('form.name', 'Ručni izbor')
            ->set('form.selected_item_ids', [$selected->id])
            ->assertSee('Prikazano je najviše 100 rezultata. Za ostale artikle upišite naziv ili šifru.');

        $this->assertCount(100, $component->get('itemOptions'));
        $this->assertNotContains($oldest->id, $component->get('itemOptions')->pluck('id'));
        $this->assertNotContains($selected->id, $component->get('itemOptions')->pluck('id'));
        $this->assertSame([$selected->id], $component->get('selectedItems')->pluck('id')->all());

        $component->set('pickerSearch', 'Najstariji pretraživi');
        $this->assertSame([$oldest->id], $component->get('itemOptions')->pluck('id')->all());
        $component->set('pickerSearch', 'OLDEST001');
        $this->assertSame([$oldest->id], $component->get('itemOptions')->pluck('id')->all());
        $component->set('pickerItemId', $oldest->id)->call('addSelectedItem')
            ->set('pickerSearch', '')->set('form.watchline.category_ids', [(string) $category->id])
            ->assertSet('form.selected_item_ids', [$selected->id, $oldest->id])
            ->assertSee('Prethodno odabran sat')->assertSee('Najstariji pretraživi sat')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('admin.content.blocks'));

        $block = ContentBlock::query()->where('code', 'watchline-bounded-picker')->firstOrFail();
        $this->assertSame([$selected->id, $oldest->id], $block->items->pluck('item_id')->all());
        Livewire::actingAs($admin)->test(Form::class, ['blockId' => $block->id])
            ->assertSet('form.selected_item_ids', [$selected->id, $oldest->id])
            ->assertSet('form.watchline.category_ids', [$category->id]);
    }

    public function test_widget_image_upload_is_saved_to_public_storage_and_reloads_as_preview_url(): void
    {
        Storage::fake('public');
        $block = $this->campaign();

        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
            ->set('watchlineImageUploads.mens_image_url', UploadedFile::fake()->image('new-watch.png', 800, 800))
            ->call('save')->assertHasNoErrors();

        $url = data_get($block->fresh()->translations->firstWhere('locale', 'hr')->payload, 'watchline.mens_image_url');
        $this->assertStringStartsWith('/storage/watchline-widgets/', $url);
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
            ->assertSet('form.watchline.mens_image_url', $url)->assertSee($url, false);
    }

    public function test_widget_fields_reject_unsafe_urls_and_non_image_uploads(): void
    {
        $block = $this->campaign();
        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
            ->set('form.watchline.mens_image_url', 'javascript:alert(1)')
            ->set('form.watchline.arrow_icon', '../invalid')
            ->call('save')->assertHasErrors(['form.watchline.mens_image_url', 'form.watchline.arrow_icon']);

        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
            ->set('watchlineImageUploads.mens_image_url', UploadedFile::fake()->create('bad.pdf', 20, 'application/pdf'))
            ->assertHasErrors(['watchlineImageUploads.mens_image_url']);

        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
            ->set('watchlineImageUploads.mens_image_url', UploadedFile::fake()->image('oversized.png')->size(5121))
            ->assertHasErrors(['watchlineImageUploads.mens_image_url' => 'max']);
    }

    public function test_settings_merge_precedence_and_public_url_normalization(): void
    {
        $block = new ContentBlock(['type' => 'watchline_campaigns', 'payload' => ['watchline' => ['mens_title' => 'Block title', 'mens_image_url' => 'javascript:bad']]]);
        $translation = new ContentBlockTranslation(['payload' => ['watchline' => ['mens_title' => 'Translated title', 'mens_url' => 'tel:+38512345']]]);
        $settings = WatchlineWidgetSettings::forBlock($block, $translation);
        $this->assertSame('Translated title', $settings['mens_title']);
        $this->assertSame('', $settings['mens_image_url']);
        $this->assertSame('tel:+38512345', $settings['mens_url']);
        $this->assertSame('15%', $settings['cluse_offer_highlight']);
    }

    public function test_product_widget_combines_multiple_filters_and_preserves_them_per_locale(): void
    {
        $category = $this->filterCategory('watches', 'Satovi');
        $child = $this->filterCategory('women', 'Ženski satovi', $category->id);
        $brand = $this->filterManufacturer('brand-a', 'Brand A');
        $inactiveBrand = $this->filterManufacturer('brand-b', 'Brand B', false);
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Form::class)
            ->set('form.type', 'watchline_products')->set('form.code', 'watchline-admin-filtered-products')
            ->set('form.name', 'Odabrana ponuda')->set('form.locale', 'hr')
            ->set('form.watchline.source', 'promotions')->set('form.watchline.product_sort', 'largest_discount')
            ->set('form.watchline.category_ids', [(string) $category->id, (string) $child->id])
            ->set('form.watchline.manufacturer_ids', [(string) $brand->id, (string) $inactiveBrand->id])
            ->set('form.watchline.include_children', false)->set('form.watchline.in_stock_only', false)
            ->set('form.watchline.popular_only', true)
            ->set('form.items_limit', 12)->call('save')->assertHasNoErrors();

        $block = ContentBlock::query()->where('code', 'watchline-admin-filtered-products')->firstOrFail();
        $payload = $block->translations->firstWhere('locale', 'hr')->payload;
        $this->assertSame([$category->id, $child->id], data_get($payload, 'watchline.category_ids'));
        $this->assertSame([$brand->id, $inactiveBrand->id], data_get($payload, 'watchline.manufacturer_ids'));
        $this->assertFalse(data_get($payload, 'watchline.include_children'));
        $this->assertFalse(data_get($payload, 'watchline.in_stock_only'));
        $this->assertTrue(data_get($payload, 'watchline.popular_only'));

        Livewire::actingAs($admin)->test(Form::class, ['blockId' => $block->id])
            ->assertSet('form.watchline.category_ids', [$category->id, $child->id])
            ->assertSet('form.watchline.manufacturer_ids', [$brand->id, $inactiveBrand->id])
            ->assertSet('form.watchline.product_sort', 'largest_discount')
            ->assertSet('form.watchline.popular_only', true)
            ->assertSee('Brand B')->set('form.locale', 'en')
            ->set('form.watchline.source', 'new_products')->set('form.watchline.category_ids', [$child->id])
            ->set('form.watchline.manufacturer_ids', [$brand->id])->call('save')->assertHasNoErrors();

        Livewire::actingAs($admin)->test(Form::class, ['blockId' => $block->id])
            ->set('form.locale', 'hr')->assertSet('form.watchline.source', 'promotions')
            ->assertSet('form.watchline.category_ids', [$category->id, $child->id])
            ->set('form.locale', 'en')->assertSet('form.watchline.source', 'new_products')
            ->assertSet('form.watchline.popular_only', false)
            ->assertSet('form.watchline.category_ids', [$child->id])
            ->assertSet('form.watchline.manufacturer_ids', [$brand->id])
            ->assertSee('oznaka Novo');
    }

    public function test_product_widget_filter_pickers_search_and_clear_independently(): void
    {
        $watches = $this->filterCategory('watches', 'Satovi');
        $jewelry = $this->filterCategory('jewelry', 'Nakit');
        $brandA = $this->filterManufacturer('alpha', 'Alpha');
        $brandB = $this->filterManufacturer('beta', 'Beta');
        $component = Livewire::actingAs($this->admin())->test(Form::class)->set('form.type', 'watchline_products')
            ->set('watchlineCategoryFilterSearch', 'Satovi')->set('watchlineManufacturerFilterSearch', 'Beta');

        $this->assertSame([$watches->id], $component->get('watchlineCategoryFilterOptions')->pluck('id')->all());
        $this->assertSame([$brandB->id], $component->get('watchlineManufacturerFilterOptions')->pluck('id')->all());
        $component->set('form.watchline.category_ids', [$jewelry->id])
            ->set('form.watchline.manufacturer_ids', [$brandA->id])
            ->call('clearWatchlineFilter', 'category_ids')->assertSet('form.watchline.category_ids', [])
            ->assertSet('form.watchline.manufacturer_ids', [$brandA->id]);
    }

    public function test_product_widget_rejects_missing_duplicate_and_non_catalog_filter_ids(): void
    {
        $category = $this->filterCategory('watches', 'Satovi');
        $contentCategory = Category::query()->create(['scope' => 'blog', 'code' => 'blog-filter-category', 'is_active' => true]);
        $component = Livewire::actingAs($this->admin())->test(Form::class)
            ->set('form.type', 'watchline_products')->set('form.code', 'watchline-invalid-filters')
            ->set('form.name', 'Ponuda')->set('form.watchline.source', 'promotions')
            ->set('form.watchline.category_ids', [$contentCategory->id])
            ->set('form.watchline.manufacturer_ids', [999999])
            ->set('form.watchline.product_sort', 'invalid')->call('save')
            ->assertHasErrors(['form.watchline.category_ids.0', 'form.watchline.manufacturer_ids.0', 'form.watchline.product_sort']);

        $component->set('form.watchline.category_ids', [$category->id, $category->id])
            ->set('form.watchline.manufacturer_ids', [])->set('form.watchline.product_sort', 'source')
            ->call('save')->assertHasErrors(['form.watchline.category_ids.0' => 'distinct']);
        $this->assertDatabaseMissing('content_blocks', ['code' => 'watchline-invalid-filters']);
    }

    public function test_product_filter_settings_normalize_id_arrays_without_scalar_conversion(): void
    {
        $block = new ContentBlock(['type' => 'watchline_products', 'payload' => ['watchline' => [
            'category_ids' => [1, '2', 2, 0, -3, 'invalid', true, null],
            'manufacturer_ids' => ['3', '3', 4],
        ]]]);
        $settings = WatchlineWidgetSettings::forBlock($block, null);
        $this->assertSame([1, 2], $settings['category_ids']);
        $this->assertSame([3, 4], $settings['manufacturer_ids']);
        $this->assertTrue($settings['include_children']);
        $this->assertTrue($settings['in_stock_only']);
        $this->assertFalse($settings['popular_only']);
        $this->assertSame('source', $settings['product_sort']);
    }

    public function test_popular_filter_explains_empty_selection_and_reacts_to_checkbox(): void
    {
        Product::query()->create(['code' => 'unmarked-active', 'sku' => 'UNMARKED', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 1]);
        Product::query()->create(['code' => 'popular-inactive', 'sku' => 'INACTIVE', 'is_active' => false, 'base_price' => 10, 'stock_qty' => 1, 'payload' => ['storefront' => ['is_popular' => true]]]);
        $warning = 'Nijedan aktivan artikl nema oznaku Popularno. Widget se neće prikazati dok ne označite artikle ili isključite ovaj filter.';

        Livewire::actingAs($this->admin())->test(Form::class)->set('form.type', 'watchline_products')
            ->assertSee('Popularno je ručna oznaka na artiklu.')
            ->assertSee(route('admin.products'), false)->assertDontSee($warning)
            ->set('form.watchline.popular_only', true)->assertSee($warning)
            ->assertSeeHtml('role="status"')->assertSet('form.watchline.popular_only', true)
            ->set('form.watchline.popular_only', false)->assertDontSee($warning);
    }

    public function test_popular_filter_warning_is_absent_when_an_active_product_is_marked(): void
    {
        Product::query()->create(['code' => 'popular-active', 'sku' => 'POPULAR', 'is_active' => true, 'base_price' => 10, 'stock_qty' => 0, 'payload' => ['storefront' => ['is_popular' => true]]]);

        Livewire::actingAs($this->admin())->test(Form::class)->set('form.type', 'watchline_products')
            ->set('form.watchline.popular_only', true)
            ->assertDontSee('Nijedan aktivan artikl nema oznaku Popularno.')
            ->assertSet('form.watchline.popular_only', true);
    }

    private function filterCategory(string $code, string $name, ?int $parentId = null): Category
    {
        $category = Category::query()->create(['scope' => Category::SCOPE_CATALOG, 'code' => $code, 'is_active' => true, 'parent_id' => $parentId]);
        $category->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => $code]);

        return $category;
    }

    private function filterManufacturer(string $code, string $name, bool $active = true): Manufacturer
    {
        $manufacturer = Manufacturer::query()->create(['code' => $code, 'is_active' => $active]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => $code]);

        return $manufacturer;
    }

    private function campaign(): ContentBlock
    {
        $block = ContentBlock::query()->create(['code' => 'watchline-admin-preserved-template', 'name' => 'Tri bannera', 'type' => 'watchline_campaigns', 'is_active' => true]);
        $block->translations()->create(['locale' => 'hr', 'payload' => ['watchline' => ['mens_title' => 'Prethodni naslov']]]);
        $block->slots()->create(['placement' => 'home.content', 'frontend_variant' => 'all', 'sort_order' => 10, 'is_active' => true]);

        return $block;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
