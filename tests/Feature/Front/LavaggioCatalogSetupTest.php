<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\User;
use App\Services\Front\NavigationMenuService;
use App\Services\Front\StoreSettingsService;
use App\Services\Settings\SystemSettingsService;
use Database\Seeders\LavaggioCatalogSeeder;
use Database\Seeders\LavaggioNavigationImagesSeeder;
use Database\Seeders\LavaggioStorefrontSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LavaggioCatalogSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set([
            'app.locale' => 'hr', 'storefront.home' => 'lavaggio',
            'storefront.theme' => 'herrera', 'storefront.brand' => 'libermedia',
        ]);
        app()->setLocale('hr');
    }

    public function test_catalog_setup_is_repeatable_and_saves_the_supplied_hierarchy_and_seo_without_touching_accounts(): void
    {
        $admin = User::factory()->create(['email' => 'tomislav@agmedia.hr', 'account_type' => 'staff']);
        $password = $admin->password;
        $this->seed(LavaggioCatalogSeeder::class);
        $ids = Category::orderBy('id')->pluck('id')->all();
        $this->seed(LavaggioCatalogSeeder::class);

        $this->assertDatabaseCount('categories', 22);
        $this->assertDatabaseCount('category_translations', 22);
        $this->assertSame($ids, Category::orderBy('id')->pluck('id')->all());
        $this->assertSame(5, Category::whereNull('parent_id')->count());
        $this->assertSame(17, Category::whereNotNull('parent_id')->count());
        $this->assertFalse(Category::isBroken());
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

        $detergents = Category::with(['translations', 'children.translations'])->where('code', 'lavaggio-deterdzenti')->sole();
        $this->assertSame(['Univerzalni', 'Bijelo rublje', 'Šareno rublje', 'Crno i tamno rublje', 'Vuna, svila i osjetljive tkanine'],
            $detergents->children->sortBy('sort_order')->map(fn ($category) => $category->translations->sole()->name)->values()->all());
        $description = 'Deterdženti za pranje rublja u perilici i ručno pranje. Pronađite tekuće deterdžente, gelove i praškove za bijelo, šareno, crno i osjetljivo rublje te svakodnevno održavanje svih vrsta tkanina.';
        $this->assertSame($description, $detergents->translations->sole()->description);
        $this->assertSame($description, $detergents->translations->sole()->meta_description);
        $this->assertSame('Deterdženti | Lavaggio web shop', $detergents->translations->sole()->meta_title);
        $this->assertDatabaseHas('category_translations', [
            'name' => 'Čišćenje i održavanje perilice', 'slug' => 'ciscenje-i-odrzavanje-perilice',
            'description' => 'Sredstva za čišćenje perilica rublja, uklanjanje kamenca, naslaga deterdženta i neugodnih mirisa. Tablete, prašci i tekuća sredstva za redovito održavanje perilice.',
        ]);
        foreach (['products', 'orders', 'catalog_manufacturers'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($password, $admin->fresh()->password);
    }

    public function test_header_and_footer_share_dynamic_catalog_and_information_links(): void
    {
        $this->seed([LavaggioStorefrontSeeder::class, LavaggioCatalogSeeder::class]);
        $navigation = app(NavigationMenuService::class);
        $items = $navigation->storefrontItemsForLocale('hr');
        $this->assertSame(['Web shop', 'Praonica', 'Usluge', 'Cjenik', 'Pitanja i odgovori', 'Blog', 'Kontakt'], array_column($items, 'label'));
        $this->assertSame('catalog', $items[0]['type']);
        $this->assertCount(5, $items[0]['children']);
        $this->assertCount(5, $items[0]['children'][0]['children']);

        $columns = app(StoreSettingsService::class)->footer()['link_columns'];
        $this->assertSame('Web shop', $columns[0]['title']);
        $this->assertSame(array_column($items[0]['children'], 'label'), array_column($columns[0]['links'], 'label'));
        $this->assertSame(array_column(array_slice($items, 1), 'label'), array_column($columns[1]['links'], 'label'));
        $this->assertSame(array_column(array_slice($items, 1), 'url'), array_column($columns[1]['links'], 'url'));

        app(SystemSettingsService::class)->put(NavigationMenuService::SETTINGS_KEY, [
            ['type' => 'custom', 'label' => 'Stari naziv', 'url' => '/shop'],
            ['type' => 'custom', 'label' => 'Naša praonica', 'url' => 'https://www.lavaggio.hr/'],
        ]);
        Category::where('code', 'lavaggio-deterdzenti')->firstOrFail()->update(['show_in_menu' => false]);
        $items = $navigation->storefrontItemsForLocale('hr');
        $this->assertSame(['Web shop', 'Naša praonica'], array_column($items, 'label'));
        $this->assertCount(4, $items[0]['children']);
        $columns = app(StoreSettingsService::class)->footer()['link_columns'];
        $this->assertCount(4, $columns[0]['links']);
        $this->assertSame(['Naša praonica'], array_column($columns[1]['links'], 'label'));
    }

    public function test_initial_photos_cover_all_roots_and_reseeding_preserves_replacements_and_removals(): void
    {
        $this->seed(LavaggioCatalogSeeder::class);
        $navigation = app(NavigationMenuService::class);
        $images = $navigation->categoryImages();
        $roots = $navigation->primaryCategoryItems('hr');
        $this->assertCount(5, $images);
        foreach ($roots as $root) {
            $this->assertNotEmpty($root['menu_image_url']);
            $this->assertNotEmpty($root['menu_image_alt']);
            $this->assertFileExists(public_path(ltrim((string) parse_url($root['menu_image_url'], PHP_URL_PATH), '/')));
        }

        $ids = array_keys($images);
        $images[$ids[0]] = ['image_path' => 'navigation/categories/custom.webp', 'image_alt' => 'Moja nova fotografija'];
        $images[$ids[1]]['image_path'] = '';
        app(SystemSettingsService::class)->put(NavigationMenuService::CATEGORY_IMAGES_SETTINGS_KEY, $images);
        $this->seed(LavaggioNavigationImagesSeeder::class);
        $this->assertSame($images, $navigation->categoryImages());
    }
}
