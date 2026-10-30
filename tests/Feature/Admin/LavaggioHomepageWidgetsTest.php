<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Livewire\Admin\Content\Block\Index;
use App\Models\Catalog\Category\Category;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\User;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioHomepageWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        config(['storefront.home' => 'lavaggio', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr', 'storefront_cache.enabled' => false]);
    }

    public function test_setup_creates_seven_ordered_widgets_and_preserves_existing_edits_and_other_blocks(): void
    {
        foreach (['deterdzenti', 'uklanjanje-mrlja', 'mirisi-i-njega-rublja', 'higijena-i-odrzavanje', 'pribor-i-dodaci-za-rublje'] as $index => $slug) {
            $category = Category::create(['code' => 'lavaggio-'.$slug, 'scope' => 'catalog', 'is_active' => true, 'sort_order' => $index]);
            $category->translations()->create(['locale' => 'hr', 'name' => $slug, 'slug' => $slug]);
        }
        $other = ContentBlock::create(['code' => 'existing-other-block', 'name' => 'Existing', 'type' => 'banner', 'is_active' => true]);
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $this->assertDatabaseCount('content_blocks', 8);
        $this->assertDatabaseCount('content_block_slots', 7);
        $this->assertSame([
            'lavaggio-home-campaigns', 'lavaggio-home-categories', 'lavaggio-home-latest',
            'lavaggio-home-brands', 'lavaggio-home-promotions', 'lavaggio-home-reviews', 'lavaggio-home-blog',
        ], ContentBlockSlot::with('block')->orderBy('sort_order')->get()->map(fn ($slot) => $slot->block->code)->all());
        $campaign = ContentBlock::where('code', 'lavaggio-home-campaigns')->sole();
        $settings = WatchlineWidgetSettings::forBlock($campaign, $campaign->translations()->sole());
        $this->assertSame('/page/usluzna-praonica-rublja', $settings['service_url']);
        $this->assertSame('/page/samoposluzna-praonica-rublja', $settings['self_url']);
        $categories = ContentBlock::where('code', 'lavaggio-home-categories')->sole();
        $categorySettings = WatchlineWidgetSettings::forBlock($categories, $categories->translations()->sole());
        $this->assertSame('Kupi u kompletu i uštedi', $categorySettings['card_6_title']);
        $this->assertSame('/bundle-artikli', $categorySettings['card_6_url']);
        $latest = ContentBlock::where('code', 'lavaggio-home-latest')->sole();
        $translation = $latest->translations()->sole();
        $this->assertSame(12, $translation->payload['items_limit']);
        $this->assertSame(6, $translation->payload['watchline']['desktop_columns']);
        $translation->update(['title' => 'Moj naslov']);
        $latest->slots()->update(['sort_order' => 1, 'is_active' => false]);
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $this->assertDatabaseCount('content_blocks', 8);
        $this->assertSame('Moj naslov', $translation->fresh()->title);
        $this->assertFalse($latest->slots()->sole()->is_active);
        $this->assertSame(1, $latest->slots()->sole()->sort_order);
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_lavaggio_editor_saves_typed_content_and_automatic_products_without_blade_templates(): void
    {
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $campaign = ContentBlock::where('code', 'lavaggio-home-campaigns')->sole();
        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $campaign->id])
            ->assertSet('form.template_body', '')
            ->assertSee('Uredite tekstove, slike i poveznice ovog widgeta.')
            ->set('form.title', 'Glavni naslov po mjeri')
            ->set('form.watchline.service_title', 'Usluga po mjeri')
            ->set('form.watchline.main_image', '/assets/lavaggio/edited.webp')
            ->call('save')->assertHasNoErrors();
        $translation = $campaign->fresh()->translations()->sole();
        $this->assertSame('Glavni naslov po mjeri', $translation->title);
        $this->assertSame('Usluga po mjeri', $translation->payload['watchline']['service_title']);
        $latest = ContentBlock::where('code', 'lavaggio-home-latest')->sole();
        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $latest->id])
            ->assertSee(__('Number of products to show'))
            ->set('form.items_limit', 18)->set('form.watchline.desktop_columns', 6)
            ->set('form.selected_item_ids', [])->call('save')->assertHasNoErrors();
        $this->assertSame(18, $latest->fresh()->translations()->sole()->payload['items_limit']);
    }

    public function test_admin_can_reorder_and_hide_lavaggio_widgets(): void
    {
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $slots = ContentBlockSlot::orderBy('sort_order')->get();
        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSee('Početna — raspored blokova')
            ->call('moveHomeWidget', $slots[1]->id, 'up')
            ->call('toggleHomeWidget', $slots[1]->id);
        $this->assertSame(10, $slots[1]->fresh()->sort_order);
        $this->assertFalse($slots[1]->fresh()->is_active);
        $this->assertFalse($slots[1]->block->fresh()->is_active);
    }

    public function test_home_renders_widget_content_and_hiding_all_widgets_does_not_restore_hardcoded_content(): void
    {
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $this->get('/')->assertOk()
            ->assertSee('data-lavaggio-home-widgets', false)
            ->assertSee('data-lavaggio-home-reviews', false)
            ->assertSee('lavaggio-home-reviews.css', false)
            ->assertSee('Uslužna praonica rublja')->assertSee('Samoposlužna praonica rublja')
            ->assertSee('Kupi u kompletu i uštedi')->assertSee('Prvi komentari kupaca stižu uskoro.');
        ContentBlock::query()->update(['is_active' => false]);
        \App\Services\Content\ContentBlockResolver::bumpCacheVersion();
        $this->get('/')->assertOk()->assertDontSee('lavaggio-widget-hero', false)
            ->assertDontSee('lavaggio-widget-reviews-empty', false);
    }

    public function test_bundle_category_is_editable_promo_without_obsolete_product_photos(): void
    {
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $block = ContentBlock::where('code', 'lavaggio-home-categories')->sole();
        $translation = $block->translations()->sole();
        $payload = $translation->payload;
        $payload['watchline']['card_6_image'] = '/obsolete-bundle-photo.webp';
        $payload['watchline']['card_6_secondary_image'] = '/obsolete-second-photo.webp';
        $translation->update(['payload' => $payload]);
        $this->get('/')->assertOk()
            ->assertSee('data-lavaggio-home-bundle-promo', false)
            ->assertDontSee('VIŠE NJEGE, VIŠE UŠTEDE')
            ->assertDontSee('Otkrijte odabrane komplete za pranje i njegu rublja.')
            ->assertSee('Pogledajte ponudu')
            ->assertDontSee('/obsolete-bundle-photo.webp')->assertDontSee('/obsolete-second-photo.webp');
        $fields = WatchlineWidgetSettings::fields('lavaggio_categories');
        $this->assertArrayHasKey('card_5_image', $fields);
        $this->assertArrayNotHasKey('card_6_image', $fields);
        $this->assertArrayNotHasKey('card_6_secondary_image', $fields);
        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
            ->set('form.watchline.card_6_cta_label', 'Moj odabir kompleta')
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Moj odabir kompleta', $translation->fresh()->payload['watchline']['card_6_cta_label']);
    }

    public function test_untranslated_product_review_keeps_honest_empty_state_until_product_is_visible(): void
    {
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $product = \App\Models\Catalog\Product\Product::create(['code' => 'review-untranslated', 'sku' => 'REVIEW-1', 'is_active' => true, 'stock_qty' => 1, 'base_price' => 10]);
        \App\Models\Content\Support\Comment::create(['commentable_type' => get_class($product), 'commentable_id' => $product->id,
            'locale' => 'hr', 'author_name' => 'Kupac', 'body' => 'Stvarni odobreni komentar', 'rating' => 4,
            'status' => \App\Models\Content\Support\Comment::STATUS_APPROVED]);
        $this->get('/')->assertOk()->assertSee('Prvi komentari kupaca stižu uskoro.')->assertDontSee('Stvarni odobreni komentar');
        $product->translations()->create(['locale' => 'hr', 'name' => 'Vidljivi proizvod', 'slug' => 'vidljivi-proizvod']);
        $this->get('/')->assertOk()->assertSee('Stvarni odobreni komentar')->assertDontSee('Prvi komentari kupaca stižu uskoro.')
            ->assertSeeInOrder(['data-lavaggio-home-reviews', 'Stvarni odobreni komentar', 'data-lavaggio-widget="lavaggio-home-blog"'], false);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
