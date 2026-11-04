<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Navigation\Manager;
use App\Models\Catalog\Category\Category;
use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ContentNavigationEditorUxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr']);
        app()->setLocale('hr');
        Storage::fake('public');
        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY, [
            ['type' => 'custom', 'label' => 'Praonica', 'url' => '/praonica', 'sort_order' => 10, 'children' => [
                ['type' => 'custom', 'label' => 'Samoposlužna', 'url' => '/samoposluzna', 'children' => [
                    ['type' => 'custom', 'label' => 'Kako prati', 'url' => '/kako-prati'],
                ]],
                ['type' => 'custom', 'label' => 'Cjenik', 'url' => '/cjenik'],
            ]],
            ['type' => 'custom', 'label' => 'Kontakt', 'url' => '/kontakt', 'sort_order' => 20],
        ]);
    }

    public function test_initial_menu_is_compact_and_details_open_only_on_request(): void
    {
        $category = $this->category();
        $component = $this->manager()->assertSet('activeTab', 'menu')
            ->assertSeeText('Praonica')->assertSeeText('Kontakt')
            ->assertDontSee('form.items.0.label"', false)
            ->assertDontSee('form.appearance.font_size"', false)
            ->assertDontSee('categoryMenuUploads.'.$category->id.'"', false);

        $component->call('toggleItem', '0')->assertSet('expandedItems.0', true)
            ->assertSee('form.items.0.label"', false)
            ->assertSeeText('Samoposlužna')
            ->assertDontSee('form.items.0.children.0.label"', false);
        $component->call('toggleItem', '0.children.0')
            ->assertSee('form.items.0.children.0.label"', false);

        $component->call('setTab', 'images')->assertSet('activeTab', 'images')
            ->assertSeeText('Deterdženti')
            ->assertDontSee('categoryMenuUploads.'.$category->id.'"', false)
            ->call('toggleCategoryImage', $category->id)
            ->assertSee('categoryMenuUploads.'.$category->id.'"', false);
    }

    public function test_switching_tabs_keeps_unsaved_edits_and_save_persists_hidden_sections(): void
    {
        $category = $this->category();
        $component = $this->manager()
            ->set('form.items.0.label', 'Nova praonica')
            ->call('setTab', 'appearance')->set('form.appearance.font_size', 18)
            ->call('setTab', 'top_bar')->set('form.top_bar.height', 40)
            ->call('setTab', 'images')->set('form.category_images.'.$category->id.'.image_alt', 'Pranje rublja')
            ->call('setTab', 'menu')
            ->assertSet('form.items.0.label', 'Nova praonica')
            ->assertSet('form.appearance.font_size', 18)
            ->assertSet('form.top_bar.height', 40)
            ->assertSet('form.category_images.'.$category->id.'.image_alt', 'Pranje rublja')
            ->call('save')->assertHasNoErrors();

        $navigation = app(NavigationMenuService::class);
        $this->assertSame('Nova praonica', $navigation->configuredItems()[0]['label']);
        $this->assertSame(18, $navigation->appearance()['font_size']);
        $this->assertSame(40, $navigation->topBar()['height']);
        $this->assertSame('Pranje rublja', $navigation->categoryImages()[$category->id]['image_alt']);
        $component->assertSet('activeTab', 'menu');
    }

    public function test_new_items_open_the_menu_and_all_required_ancestor_details(): void
    {
        $this->manager()->call('setTab', 'appearance')->call('addCustomItem')
            ->assertSet('activeTab', 'menu')->assertSet('expandedItems.2', true)
            ->assertSee('form.items.2.label"', false)
            ->call('addChildItem', '0.children.0', 'custom')
            ->assertSet('expandedItems.0', true)
            ->assertSet('expandedItems', fn (array $items) => ! empty($items['0.children.0']))
            ->assertSet('expandedItems', fn (array $items) => ! empty($items['0.children.0.children.1']))
            ->assertSee('form.items.0.children.0.children.1.label"', false);
    }

    public function test_reordering_items_preserves_their_open_details_and_pending_photos(): void
    {
        $component = $this->manager()->call('toggleItem', '0')->call('toggleItem', '0.children.0')
            ->set('desktopPromoUploads.0', UploadedFile::fake()->image('praonica.jpg'))
            ->set('desktopPromoUploads.0_children_0', UploadedFile::fake()->image('samoposluzna.jpg'))
            ->call('moveDown', 0)
            ->assertSet('form.items.1.label', 'Praonica')
            ->assertSet('expandedItems.1', true)
            ->assertSet('expandedItems', fn (array $items) => ! empty($items['1.children.0']))
            ->call('moveNestedItem', '1.children.0', 1)
            ->assertSet('form.items.1.children.1.label', 'Samoposlužna')
            ->assertSet('expandedItems', fn (array $items) => ! empty($items['1.children.1']));

        $uploads = $component->get('desktopPromoUploads');
        $this->assertArrayNotHasKey('0_children_0', $uploads);
        $this->assertSame('praonica.jpg', $uploads[1]->getClientOriginalName());
        $this->assertSame('samoposluzna.jpg', $uploads['1_children_1']->getClientOriginalName());

        $component->call('save')->assertHasNoErrors();
        $items = app(NavigationMenuService::class)->configuredItems();
        Storage::disk('public')->assertExists($items[1]['desktop_promo_image_path']);
        Storage::disk('public')->assertExists($items[1]['children'][1]['desktop_promo_image_path']);
        $this->assertSame('', $items[0]['desktop_promo_image_path']);
        $this->assertSame('', $items[1]['children'][0]['desktop_promo_image_path']);
    }

    public function test_removing_items_discards_their_pending_photos_and_does_not_open_their_replacements(): void
    {
        $component = $this->manager()->call('toggleItem', '0')->call('toggleItem', '0.children.0')
            ->set('desktopPromoUploads.0', UploadedFile::fake()->image('praonica.jpg'))
            ->set('desktopPromoUploads.0_children_0', UploadedFile::fake()->image('samoposluzna.jpg'))
            ->call('removeNestedItem', '0.children.0')
            ->assertSet('form.items.0.children.0.label', 'Cjenik')
            ->assertSet('expandedItems.0', true);
        $this->assertArrayNotHasKey('0.children.0', $component->get('expandedItems'));
        $this->assertArrayNotHasKey('0_children_0', $component->get('desktopPromoUploads'));

        $component->call('removeItem', 0)->assertSet('form.items.0.label', 'Kontakt')
            ->assertSet('expandedItems', [])->assertSet('desktopPromoUploads', []);
    }

    #[DataProvider('hiddenPanelErrors')]
    public function test_validation_opens_the_panel_containing_the_error(string $field, mixed $value, string $tab): void
    {
        $component = $this->manager()->set($field, $value)->call('save')
            ->assertHasErrors($field)->assertSet('activeTab', $tab);
        $component->assertSee($field.'"', false);
    }

    public static function hiddenPanelErrors(): array
    {
        return [
            'appearance' => ['form.appearance.font_size', 1, 'appearance'],
            'top bar' => ['form.top_bar.height', 1, 'top_bar'],
        ];
    }

    public function test_category_validation_opens_the_images_tab_and_category_details(): void
    {
        $category = $this->category();
        $this->manager()->set('form.category_images.'.$category->id.'.image_alt', str_repeat('x', 256))
            ->call('save')->assertHasErrors('form.category_images.'.$category->id.'.image_alt')
            ->assertSet('activeTab', 'images')
            ->assertSet('expandedCategories.'.$category->id, true)
            ->assertSee('form.category_images.'.$category->id.'.image_alt"', false);
    }

    public function test_invalid_photo_upload_reopens_its_tab_and_details(): void
    {
        $category = $this->category();
        $this->manager()->call('setTab', 'appearance')
            ->set('categoryMenuUploads.'.$category->id, UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'))
            ->assertHasErrors('categoryMenuUploads.'.$category->id)
            ->assertSet('activeTab', 'images')
            ->assertSet('expandedCategories.'.$category->id, true);

        $this->manager()->call('setTab', 'appearance')
            ->set('desktopPromoUploads.0_children_0', UploadedFile::fake()->create('document.pdf', 10, 'application/pdf'))
            ->assertHasErrors('desktopPromoUploads.0_children_0')
            ->assertSet('activeTab', 'menu')
            ->assertSet('expandedItems.0', true)
            ->assertSet('expandedItems', fn (array $items) => ! empty($items['0.children.0']));
    }

    public function test_nested_reference_errors_reopen_the_menu_and_all_ancestor_details(): void
    {
        $before = app(NavigationMenuService::class)->configuredItems();
        $this->manager()->set('form.items.0.children.0.children.0.type', 'page')
            ->set('form.items.0.children.0.children.0.page_id', 0)
            ->call('setTab', 'appearance')->call('save')
            ->assertHasErrors('form.items.0.children.0.children.0.page_id')
            ->assertSet('activeTab', 'menu')
            ->assertSet('expandedItems.0', true)
            ->assertSet('expandedItems', fn (array $items) => ! empty($items['0.children.0']))
            ->assertSet('expandedItems', fn (array $items) => ! empty($items['0.children.0.children.0']))
            ->assertSee('form.items.0.children.0.children.0.page_id"', false);
        $this->assertSame($before, app(NavigationMenuService::class)->configuredItems());
    }

    public function test_invalid_tabs_paths_and_categories_do_not_change_editor_state(): void
    {
        $component = $this->manager();
        $before = $component->get('form');
        foreach (['0.children.99', '../0', 'form.items.0', '0.children.0.children.0.children.0.children.0'] as $path) {
            $component->call('toggleItem', $path);
        }
        $component->call('setTab', 'unknown')->call('toggleCategoryImage', 999999)
            ->assertSet('activeTab', 'menu')->assertSet('expandedItems', [])->assertSet('expandedCategories', []);
        $this->assertSame($before, $component->get('form'));

        config(['storefront.home' => 'watchline']);
        $this->manager()->call('setTab', 'images')->assertSet('activeTab', 'menu');
    }

    private function category(): Category
    {
        $category = Category::query()->create(['scope' => Category::SCOPE_CATALOG, 'code' => 'detergents', 'is_active' => true]);
        $category->translations()->create(['scope' => Category::SCOPE_CATALOG, 'locale' => 'hr', 'name' => 'Deterdženti', 'slug' => 'deterdzenti']);

        return $category;
    }

    private function manager()
    {
        $admin = User::factory()->create();
        Bouncer::assign('admin')->to($admin);

        return Livewire::actingAs($admin)->test(Manager::class);
    }
}
