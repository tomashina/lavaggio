<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Navigation\Manager as NavigationManager;
use App\Models\Catalog\Category\Category;
use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioNavigationImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set(['app.locale' => 'hr', 'storefront.home' => 'lavaggio', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        Storage::fake('public');
    }

    public function test_admin_can_upload_replace_and_remove_a_root_menu_photo_without_changing_other_categories(): void
    {
        $user = $this->admin();
        $root = $this->category('Deterdženti', 'deterdzenti');
        $other = $this->category('Pribor', 'pribor');
        $child = $this->category('Univerzalni', 'univerzalni', $root);
        Storage::disk('public')->put('navigation/categories/pribor.jpg', 'existing');
        $settings = app(SystemSettingsService::class);
        $settings->put(NavigationMenuService::CATEGORY_IMAGES_SETTINGS_KEY, [$other->id => [
            'image_path' => 'navigation/categories/pribor.jpg', 'image_alt' => 'Pribor za pranje',
        ]]);

        $component = Livewire::actingAs($user)->test(NavigationManager::class)
            ->assertSee('Slike kategorija u Web shop izborniku')
            ->assertSet('form.category_images.'.$root->id.'.image_path', '')
            ->set('form.category_images.'.$root->id.'.image_alt', 'Tekući deterdženti i svježe rublje')
            ->set('categoryMenuUploads.'.$root->id, UploadedFile::fake()->image('deterdzenti.jpg', 800, 600))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('categoryMenuUploads', []);

        $images = app(NavigationMenuService::class)->categoryImages();
        $firstPath = $images[$root->id]['image_path'];
        Storage::disk('public')->assertExists($firstPath);
        $this->assertStringStartsWith('navigation/categories/', $firstPath);
        $this->assertSame('navigation/categories/pribor.jpg', $images[$other->id]['image_path']);
        $this->assertArrayNotHasKey($child->id, $images);

        $entry = app(NavigationMenuService::class)->primaryCategoryItems('hr')[0];
        $this->assertSame(Storage::disk('public')->url($firstPath), $entry['menu_image_url']);
        $this->assertSame('Tekući deterdženti i svježe rublje', $entry['menu_image_alt']);
        $this->assertSame('', $entry['image_url']);

        $component->set('categoryMenuUploads.'.$root->id, UploadedFile::fake()->image('nova.jpg', 1000, 750))
            ->call('save')->assertHasNoErrors();
        $replacement = app(NavigationMenuService::class)->categoryImages()[$root->id]['image_path'];
        $this->assertNotSame($firstPath, $replacement);
        Storage::disk('public')->assertExists($replacement);

        Livewire::actingAs($user)->test(NavigationManager::class)
            ->assertSet('form.category_images.'.$root->id.'.image_path', $replacement)
            ->call('clearCategoryMenuImage', $root->id)->call('save')->assertHasNoErrors();

        $this->assertSame('', app(NavigationMenuService::class)->categoryImages()[$root->id]['image_path']);
        $this->assertSame('', app(NavigationMenuService::class)->primaryCategoryItems('hr')[0]['menu_image_url']);
        $this->assertSame('navigation/categories/pribor.jpg', app(NavigationMenuService::class)->categoryImages()[$other->id]['image_path']);
    }

    public function test_invalid_or_oversized_uploads_do_not_replace_saved_photos(): void
    {
        $root = $this->category('Deterdženti', 'deterdzenti');
        $before = [$root->id => ['image_path' => '/assets/existing.webp', 'image_alt' => 'Deterdženti']];
        app(SystemSettingsService::class)->put(NavigationMenuService::CATEGORY_IMAGES_SETTINGS_KEY, $before);
        $user = $this->admin();

        Livewire::actingAs($user)->test(NavigationManager::class)
            ->set('categoryMenuUploads.'.$root->id, UploadedFile::fake()->create('dokument.pdf', 10, 'application/pdf'))
            ->assertHasErrors(['categoryMenuUploads.'.$root->id => 'image']);
        Livewire::actingAs($user)->test(NavigationManager::class)
            ->set('categoryMenuUploads.'.$root->id, UploadedFile::fake()->image('prevelika.jpg')->size(4097))
            ->assertHasErrors(['categoryMenuUploads.'.$root->id => 'max']);

        $this->assertSame($before, app(NavigationMenuService::class)->categoryImages());
        $this->assertSame([], Storage::disk('public')->allFiles('navigation/categories'));
    }

    public function test_new_root_categories_appear_automatically_and_missing_images_do_not_create_broken_urls(): void
    {
        $root = $this->category('Njega', 'njega');
        $hidden = $this->category('U pripremi', 'u-pripremi');
        $hidden->update(['is_active' => false]);
        $settings = app(SystemSettingsService::class);
        $settings->put(NavigationMenuService::CATEGORY_IMAGES_SETTINGS_KEY, [
            $root->id => ['image_path' => 'navigation/categories/ne-postoji.jpg', 'image_alt' => ''],
            $hidden->id => ['image_path' => 'navigation/categories/skica.jpg', 'image_alt' => 'Skica'],
        ]);
        $newRoot = $this->category('Novi pribor', 'novi-pribor');

        Livewire::actingAs($this->admin())->test(NavigationManager::class)
            ->assertSee('Novi pribor')
            ->assertSet('form.category_images.'.$newRoot->id.'.image_path', '')
            ->call('save')->assertHasNoErrors();

        $navigation = app(NavigationMenuService::class);
        $entry = $navigation->primaryCategoryItems('hr')[0];
        $this->assertSame('', $entry['menu_image_url']);
        $this->assertSame('Njega', $entry['menu_image_alt']);
        $this->assertSame('Skica', $navigation->categoryImages()[$hidden->id]['image_alt']);
        $this->assertSame('', $navigation->categoryMenuImageUrl('/assets/lavaggio/ne-postoji.webp'));
        $this->assertSame('', $navigation->categoryMenuImageUrl('../../.env'));

        $root->translations()->update(['name' => 'Nova njega']);
        $this->assertSame('Nova njega', $navigation->primaryCategoryItems('hr')[0]['menu_image_alt']);

        config()->set('storefront.home', 'watchline');
        $settings->put(NavigationMenuService::SETTINGS_KEY, [['type' => 'category', 'category_id' => $root->id]]);
        $this->assertArrayNotHasKey('menu_image_url', $navigation->forLocale('hr')[0]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function category(string $name, string $slug, ?Category $parent = null): Category
    {
        $category = Category::create([
            'scope' => Category::SCOPE_CATALOG, 'code' => $slug,
            'is_active' => true, 'show_in_menu' => true, 'parent_id' => $parent?->id,
        ]);
        $category->translations()->create([
            'scope' => Category::SCOPE_CATALOG, 'locale' => 'hr', 'name' => $name, 'slug' => $slug,
        ]);

        return $category;
    }
}
