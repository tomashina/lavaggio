<?php

namespace Tests\Feature\Front;

use App\Livewire\Admin\Content\Block\Index as BlockIndex;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\Content\Support\Comment;
use App\Models\User;
use App\Services\Content\ContentBlockResolver;
use App\Services\Front\WatchlineHeroService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WatchlineHomepageWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private Product $cluseProduct;

    private Product $guessProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        config([
            'app.name' => 'Watchline', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.theme' => 'herrera', 'storefront.home' => 'watchline', 'storefront.brand' => 'watchline',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false, 'storefront_cache.enabled' => false,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
            'database.connections.watchline_source.host' => 'source-must-not-be-used.invalid',
        ]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Watchline', 'store_announcement_enabled' => false]);
        $watches = Category::create(['code' => 'watches', 'scope' => 'catalog', 'is_active' => true]);
        $watches->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Satovi', 'slug' => 'satovi']);
        $cluse = $this->manufacturer('cluse', 'CLUSE', 'cluse');
        $guess = $this->manufacturer('guess', 'GUESS', 'guess-satovi');
        $this->cluseProduct = $this->product('widget-cluse', $cluse, $watches);
        $this->guessProduct = $this->product('widget-guess', $guess, $watches);
        $hero = $this->mock(WatchlineHeroService::class);
        $hero->shouldReceive('products')->andReturn(new Collection([$this->cluseProduct]));
        $hero->shouldReceive('rankedProductIds')->andReturn(collect([$this->cluseProduct->id]));
        $promotion = CatalogAction::create(['code' => 'widget-promotion', 'scope' => 'product', 'type' => 'fixed_amount',
            'discount_value' => 10, 'target_type' => 'product', 'audience_type' => 'all', 'is_active' => true]);
        $promotion->targets()->create(['target_type' => 'product', 'target_id' => $this->cluseProduct->id]);
        Comment::create(['commentable_type' => Product::class, 'commentable_id' => $this->cluseProduct->id,
            'locale' => 'hr', 'author_name' => 'Kupac', 'body' => 'Stvarna recenzija iz widgeta', 'rating' => 4, 'status' => Comment::STATUS_APPROVED]);
        $post = BlogPost::create(['code' => 'widget-blog', 'is_active' => true, 'published_at' => now()->subDay()]);
        $post->translations()->create(['locale' => 'hr', 'title' => 'Članak iz widgeta', 'slug' => 'widget-blog']);
    }

    public function test_migration_registers_all_home_and_footer_widgets_and_keeps_removed_assurance_inactive(): void
    {
        $this->configure();

        $this->assertDatabaseCount('content_blocks', 11);
        $this->assertSame(10, ContentBlockSlot::where('placement', 'home.content')->count());
        $this->assertSame(1, ContentBlockSlot::where('placement', 'footer.benefits')->count());
        $assurance = $this->block('assurance');
        $this->assertFalse($assurance->is_active);
        $this->assertFalse($assurance->slots()->sole()->is_active);
        $response = $this->get('/')->assertOk();
        $this->assertSame($this->defaultMainOrder(), $this->mainWidgetCodes($response->getContent()));
        $response->assertDontSee('data-libermedia-assurance', false)
            ->assertSee('Stvarna recenzija iz widgeta')->assertSee('Članak iz widgeta')
            ->assertSee('data-watchline-widget="watchline-home-benefits"', false);
        $this->get(route('contact.create'))->assertOk()
            ->assertSee('data-watchline-widget="watchline-home-benefits"', false)
            ->assertDontSee('data-watchline-home-widgets', false);
    }

    public function test_repeating_migration_preserves_edited_text_selection_disabled_state_and_order(): void
    {
        $this->configure();
        $cluse = $this->block('cluse');
        $translation = $cluse->translations()->where('locale', 'hr')->sole();
        $translation->update(['title' => 'Moj uređeni odabir satova', 'cta_label' => 'Moj odabir', 'cta_url' => '/manufacturer/cluse',
            'payload' => ['items_limit' => 1, 'watchline' => ['source' => 'manual', 'eyebrow' => 'Po mjeri trgovine']]]);
        $cluse->items()->delete();
        $cluse->items()->create(['item_type' => 'product', 'item_id' => $this->guessProduct->id, 'sort_order' => 7]);
        $guess = $this->block('guess');
        $guess->update(['is_active' => false]);
        $guess->slots()->update(['is_active' => false]);
        $locations = $this->block('locations');
        $locations->slots()->update(['sort_order' => 1]);
        $assurance = $this->block('assurance');
        $assurance->slots()->update(['sort_order' => 2, 'is_active' => false]);
        $beforeTranslation = $translation->fresh()->getRawOriginal();
        $beforeItem = $cluse->items()->sole()->getRawOriginal();
        $beforeLocationSlot = $locations->slots()->sole()->getRawOriginal();

        $this->configure();

        $this->assertDatabaseCount('content_blocks', 11);
        $this->assertDatabaseCount('content_block_slots', 11);
        $this->assertSame($beforeTranslation, $translation->fresh()->getRawOriginal());
        $this->assertSame($beforeItem, $cluse->items()->sole()->getRawOriginal());
        $this->assertSame($beforeLocationSlot, $locations->slots()->sole()->getRawOriginal());
        $this->assertFalse($guess->fresh()->is_active);
        $this->assertFalse($guess->slots()->sole()->is_active);
        $this->assertFalse($assurance->fresh()->is_active);
        $this->assertFalse($assurance->slots()->sole()->is_active);
        $this->assertSame(2, $assurance->slots()->sole()->sort_order);
        $response = $this->get('/')->assertOk()->assertSee('Moj uređeni odabir satova')->assertSee('Po mjeri trgovine')
            ->assertDontSee('data-watchline-guess-products', false);
        $codes = $this->mainWidgetCodes($response->getContent());
        $this->assertSame('watchline-home-locations', $codes[0]);
        $this->assertNotContains('watchline-home-guess', $codes);
        $this->assertNotContains('watchline-home-assurance', $codes);
    }

    public function test_disabling_every_widget_leaves_no_hardcoded_home_or_footer_fallbacks(): void
    {
        $this->configure();
        ContentBlock::query()->update(['is_active' => false]);
        ContentBlockSlot::query()->update(['is_active' => false]);
        ContentBlockResolver::bumpCacheVersion();

        $response = $this->get('/')->assertOk();
        $this->assertSame([], $this->mainWidgetCodes($response->getContent()));
        foreach (['data-libermedia-campaigns', 'data-watchline-cluse-products', 'data-watchline-guess-products',
            'data-watchline-locations', 'data-libermedia-creative-products', 'data-herrera-brand-carousel',
            'data-libermedia-promotions', 'data-libermedia-assurance', 'data-libermedia-home-reviews',
            'data-libermedia-home-blog', 'data-libermedia-home-benefits', 'data-watchline-widget="watchline-home-benefits"'] as $marker) {
            $response->assertDontSee($marker, false);
        }
    }

    public function test_admin_manager_reorders_widgets_and_toggles_home_and_global_footer_immediately(): void
    {
        $this->configure();
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.content.blocks'))->assertOk();
        $locationsSlot = $this->block('locations')->slots()->sole();
        $reviewsSlot = $this->block('reviews')->slots()->sole();
        $footerSlot = $this->block('benefits')->slots()->sole();
        $manager = Livewire::actingAs($admin)->test(BlockIndex::class);
        $manager->call('moveHomeWidget', $locationsSlot->id, 'up')->assertHasNoErrors();
        $response = $this->get('/')->assertOk();
        $expected = $this->defaultMainOrder();
        [$expected[2], $expected[3]] = [$expected[3], $expected[2]];
        $this->assertSame($expected, $this->mainWidgetCodes($response->getContent()));
        $manager->call('toggleHomeWidget', $reviewsSlot->id)->assertHasNoErrors();
        $this->get('/')->assertOk()->assertDontSee('data-watchline-widget="watchline-home-reviews"', false)
            ->assertDontSee('data-libermedia-home-reviews', false);
        $manager->call('toggleHomeWidget', $reviewsSlot->id)->assertHasNoErrors();
        $this->get('/')->assertOk()->assertSee('Stvarna recenzija iz widgeta');
        $manager->call('toggleHomeWidget', $footerSlot->id)->assertHasNoErrors();
        $this->get(route('contact.create'))->assertOk()->assertDontSee('data-libermedia-home-benefits', false);
        $this->assertFalse($footerSlot->fresh()->is_active);
        $this->assertFalse($footerSlot->block->fresh()->is_active);
    }

    public function test_deleting_a_watchline_widget_never_deletes_the_shared_design_template(): void
    {
        $this->configure();
        $campaigns = $this->block('campaigns');
        $path = resource_path('views/front/content-blocks/instances/watchline-home-campaigns.blade.php');
        $this->assertFileExists($path);
        File::partialMock()->shouldNotReceive('delete');

        Livewire::actingAs($this->admin())->test(BlockIndex::class)->call('delete', $campaigns->id)->assertHasNoErrors();

        $this->assertDatabaseMissing('content_blocks', ['id' => $campaigns->id]);
        $this->assertFileExists($path);
        $this->get('/')->assertOk()->assertDontSee('data-libermedia-campaigns', false);
    }

    private function configure(): void
    {
        $this->artisan('watchline:configure-home')->assertSuccessful();
    }

    private function block(string $suffix): ContentBlock
    {
        return ContentBlock::where('code', 'watchline-home-'.$suffix)->sole();
    }

    private function defaultMainOrder(): array
    {
        return array_map(fn ($suffix) => 'watchline-home-'.$suffix,
            ['campaigns', 'cluse', 'guess', 'locations', 'creative-products', 'brands', 'promotions', 'reviews', 'blog']);
    }

    private function mainWidgetCodes(string $html): array
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query('//*[@data-watchline-home-widgets]//*[@data-watchline-widget]');

        return array_map(fn ($node) => $node->getAttribute('data-watchline-widget'), iterator_to_array($nodes));
    }

    private function admin(): User
    {
        $user = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('superadmin')->to($user);

        return $user;
    }

    private function manufacturer(string $code, string $name, string $slug): Manufacturer
    {
        $manufacturer = Manufacturer::create(['code' => $code, 'is_active' => true, 'payload' => ['opencart' => ['image' => 'catalog/brands/'.$code.'.svg']]]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => $slug]);

        return $manufacturer;
    }

    private function product(string $slug, Manufacturer $manufacturer, Category $category): Product
    {
        $product = Product::create(['code' => $slug, 'sku' => $slug, 'manufacturer_id' => $manufacturer->id,
            'is_active' => true, 'stock_qty' => 3, 'base_price' => 100,
            'payload' => ['opencart' => ['image' => 'catalog/'.$slug.'.jpg', 'date_added' => '2026-10-20 10:00:00']]]);
        $product->translations()->create(['locale' => 'hr', 'name' => $slug, 'slug' => $slug]);
        $product->categories()->attach($category);

        return $product;
    }
}
