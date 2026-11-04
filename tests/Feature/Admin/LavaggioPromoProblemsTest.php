<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Models\User;
use App\Support\LavaggioCampaignSlides;
use App\Support\LavaggioHomeProblems;
use App\Support\LavaggioProductFilters;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioPromoProblemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        config(['storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera', 'app.locale' => 'hr',
            'app.fallback_locale' => 'hr', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $this->app['env'] = 'testing';
    }

    public function test_problem_widget_setup_is_preview_by_default_and_never_overwrites_existing_content(): void
    {
        $campaign = $this->campaign();
        $campaign->slots()->update(['sort_order' => 7]);
        $before = $campaign->translations()->sole()->toArray();
        $this->artisan('lavaggio:configure-problem-widget')->assertSuccessful();
        $this->assertDatabaseMissing('content_blocks', ['code' => 'lavaggio-home-problems']);
        $this->artisan('lavaggio:configure-problem-widget', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, $campaign->translations()->sole()->toArray());
        $this->assertSame(7, $campaign->slots()->sole()->sort_order);
        $problems = ContentBlock::where('code', 'lavaggio-home-problems')->sole();
        $problems->translations()->update(['title' => 'Naša rješenja']);
        $problems->slots()->update(['sort_order' => 89, 'is_active' => false]);
        $this->artisan('lavaggio:configure-problem-widget', ['--apply' => true])->assertSuccessful();
        $this->assertSame('Naša rješenja', $problems->translations()->sole()->title);
        $this->assertSame(89, $problems->slots()->sole()->sort_order);
        $this->assertFalse($problems->slots()->sole()->is_active);
    }

    public function test_legacy_banner_remains_and_both_laundry_panels_are_removed_from_home(): void
    {
        $this->get('/')->assertOk()->assertSee('Njega rublja s potpisom Lavaggio.')
            ->assertSee('data-lavaggio-promotions', false)->assertDontSee('lavaggio-widget-hero__service', false);
        $this->editor()->assertSee('Promo slajdovi')->assertDontSee('id="watchline-field-self_title"', false)
            ->assertDontSee('id="watchline-field-service_title"', false);
    }

    public function test_problem_widget_setup_rejects_a_database_outside_the_verified_destination(): void
    {
        config(['database.connections.sqlite.database' => base_path('another-preview.sqlite')]);
        $this->artisan('lavaggio:configure-problem-widget', ['--apply' => true])->assertFailed();
        config(['database.connections.sqlite.database' => ':memory:']);
        $this->assertDatabaseMissing('content_blocks', ['code' => 'lavaggio-home-problems']);
    }

    public function test_opening_pair_respects_adjacent_order_and_visibility_without_moving_other_widgets(): void
    {
        $this->artisan('lavaggio:configure-problem-widget', ['--apply' => true])->assertSuccessful();
        $problems = ContentBlock::where('code', 'lavaggio-home-problems')->sole();
        $this->get('/')->assertOk()->assertSee('data-lavaggio-home-intro', false)
            ->assertSeeInOrder(['data-lavaggio-home-intro', 'data-lavaggio-widget="lavaggio-home-campaigns"',
                'data-lavaggio-widget="lavaggio-home-problems"', 'data-lavaggio-widget="lavaggio-home-categories"'], false);

        $problems->slots()->update(['sort_order' => 25]);
        \App\Services\Content\ContentBlockResolver::bumpCacheVersion();
        $this->get('/')->assertOk()->assertDontSee('data-lavaggio-home-intro', false)
            ->assertSeeInOrder(['data-lavaggio-widget="lavaggio-home-campaigns"', 'data-lavaggio-widget="lavaggio-home-categories"',
                'data-lavaggio-widget="lavaggio-home-problems"'], false);

        $problems->slots()->update(['sort_order' => 5]);
        \App\Services\Content\ContentBlockResolver::bumpCacheVersion();
        $this->get('/')->assertOk()->assertDontSee('data-lavaggio-home-intro', false)
            ->assertSeeInOrder(['data-lavaggio-widget="lavaggio-home-problems"', 'data-lavaggio-widget="lavaggio-home-campaigns"'], false);

        $problems->slots()->update(['sort_order' => 15, 'is_active' => false]);
        \App\Services\Content\ContentBlockResolver::bumpCacheVersion();
        $this->get('/')->assertOk()->assertDontSee('data-lavaggio-home-intro', false)
            ->assertSee('data-lavaggio-promotions', false)->assertDontSee('data-lavaggio-problems', false);

        $problems->slots()->update(['is_active' => true]);
        $this->editor()->call('addLavaggioSlide')->set('form.watchline.slides.0.enabled', false)
            ->set('form.watchline.slides.1.enabled', false)->call('save')->assertHasNoErrors();
        $this->get('/')->assertOk()->assertDontSee('data-lavaggio-home-intro', false)
            ->assertDontSee('data-lavaggio-promotions', false)->assertSee('data-lavaggio-problems', false);
    }

    public function test_multiple_slides_keep_legacy_content_and_uploaded_images_follow_reordering(): void
    {
        Storage::fake('public');
        $this->editor()->call('addLavaggioSlide')
            ->assertSet('form.watchline.slides.0.title', 'Njega rublja s potpisom Lavaggio.')
            ->set('form.watchline.slides.1.title', 'Promocija za osjetljivo rublje')
            ->set('form.watchline.slides.1.subtitle', 'Odabrana njega na jednom mjestu.')
            ->set('form.watchline.slides.1.cta_label', 'Pogledajte promociju')
            ->set('form.watchline.slides.1.url', '/shop?promo=osjetljivo')
            ->set('form.watchline.slides.1.image_alt', 'Fotografija njege osjetljivog rublja')
            ->set('lavaggioSlideUploads.1', UploadedFile::fake()->image('promo.png', 1200, 800))
            ->call('moveLavaggioSlide', 1, 'up')->call('save')->assertHasNoErrors();
        $slides = $this->campaign()->translations()->sole()->payload['watchline']['slides'];
        $this->assertSame('Promocija za osjetljivo rublje', $slides[0]['title']);
        $this->assertSame('Njega rublja s potpisom Lavaggio.', $slides[1]['title']);
        Storage::disk('public')->assertExists(substr($slides[0]['image'], strlen('/storage/')));
        $this->editor()->assertSet('form.watchline.slides.0.image', $slides[0]['image'])
            ->assertDontSeeHtml('wire:model="form.title"');
        $this->get('/')->assertOk()->assertSeeInOrder(['Promocija za osjetljivo rublje', 'Njega rublja s potpisom Lavaggio.'])
            ->assertSee('data-herrera-home-carousel', false)->assertSee($slides[0]['image'], false)
            ->assertSee('Prethodna promocija')->assertSee('promo=osjetljivo');
    }

    public function test_photo_only_and_disabled_slides_render_without_stale_buttons_or_fallback(): void
    {
        $this->editor()->call('addLavaggioSlide')
            ->set('form.watchline.slides.0.enabled', false)
            ->set('form.watchline.slides.1.title', 'Naziv slikovne promocije')
            ->set('form.watchline.slides.1.subtitle', 'Ovaj opis se ne prikazuje')
            ->set('form.watchline.slides.1.cta_label', 'Slikovna promocija')
            ->set('form.watchline.slides.1.display', 'image')
            ->set('form.watchline.slides.1.image', '/assets/lavaggio/promo-test.webp')
            ->set('form.watchline.slides.1.url', '')
            ->call('save')->assertHasNoErrors();
        $this->get('/')->assertOk()->assertSee('lavaggio-promo-slide--image', false)
            ->assertSee('/assets/lavaggio/promo-test.webp')->assertDontSee('Ovaj opis se ne prikazuje')
            ->assertDontSee('Njega rublja s potpisom Lavaggio.');
        $this->editor()->set('form.watchline.slides.1.enabled', false)->call('save')->assertHasNoErrors();
        $this->get('/')->assertOk()->assertDontSee('data-lavaggio-promotions', false);
    }

    public function test_slide_links_are_validated_and_unsafe_images_never_render(): void
    {
        $this->editor()->call('addLavaggioSlide')->set('form.watchline.slides.1.url', 'javascript:alert(1)')
            ->call('save')->assertHasErrors('form.watchline.slides.1.url');
        $this->assertSame('', LavaggioCampaignSlides::normalize([['image' => 'javascript:alert(1)']])[0]['image']);
        $this->editor()->call('addLavaggioSlide')
            ->set('lavaggioSlideUploads.1', UploadedFile::fake()->create('promo.pdf', 1, 'application/pdf'))
            ->assertHasErrors('lavaggioSlideUploads.1');
    }

    public function test_problem_cards_use_real_filters_and_categories_and_remain_editable(): void
    {
        LavaggioProductFilters::seed();
        foreach (['uklanjanje-mrlja', 'ciscenje-i-odrzavanje-perilice'] as $slug) {
            $category = Category::create(['code' => 'lavaggio-'.$slug, 'scope' => 'catalog', 'is_active' => true]);
            $category->translations()->create(['locale' => 'hr', 'name' => $slug, 'slug' => $slug]);
        }
        $this->artisan('lavaggio:configure-problem-widget', ['--apply' => true])->assertSuccessful();
        $block = ContentBlock::where('code', 'lavaggio-home-problems')->sole();
        $settings = WatchlineWidgetSettings::forBlock($block, $block->translations()->sole());
        $cards = LavaggioHomeProblems::cards($settings);
        $this->assertCount(6, $cards);
        $this->assertStringContainsString('attr_lavaggio_problem=', $cards[0]['url']);
        $this->assertSame(route('categories.show', 'uklanjanje-mrlja'), $cards[2]['url']);
        $this->assertStringContainsString('attr_lavaggio_fabric=', $cards[4]['url']);
        $this->assertSame(route('categories.show', 'ciscenje-i-odrzavanje-perilice'), $cards[5]['url']);
        Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $block->id])
            ->set('form.title', 'Pronađite svoju njegu')->set('form.watchline.card_1_title', 'Naš mirisni izbor')
            ->set('form.watchline.card_1_url', '/shop?izbor=miris')
            ->set('form.watchline.card_1_order', 100)->set('form.watchline.card_2_enabled', false)
            ->call('save')->assertHasNoErrors();
        $cards = LavaggioHomeProblems::cards(WatchlineWidgetSettings::forBlock($block, $block->translations()->sole()));
        $this->assertCount(5, $cards);
        $this->assertSame('Naš mirisni izbor', $cards[4]['title']);
        $this->get('/')->assertOk()->assertSee('Pronađite svoju njegu')->assertSee('izbor=miris')
            ->assertSee('data-lavaggio-problems', false)->assertDontSee('Sivo i požutjelo rublje');
    }

    public function test_promo_example_preview_and_apply_preserve_the_banner_and_are_idempotent(): void
    {
        $this->preparePromoExampleCatalogue();
        $block = $this->campaign();
        $translation = $block->translations()->sole();
        $payload = $translation->payload;
        $payload['editor_note'] = 'Sačuvaj uredničke podatke';
        $payload['watchline']['main_image'] = '/assets/lavaggio/custom-banner.webp';
        $payload['watchline']['main_image_alt'] = 'Naš postojeći banner';
        $translation->update(['title' => 'Naša postojeća promocija', 'cta_url' => '/trgovina?izbor=postojeci', 'payload' => $payload]);
        $before = $translation->fresh()->toArray();
        $legacy = LavaggioCampaignSlides::legacy(WatchlineWidgetSettings::forBlock($block, $translation->fresh()));
        $this->artisan('lavaggio:configure-promo-examples')->assertSuccessful();
        $this->assertSame($before, $translation->fresh()->toArray());
        $this->artisan('lavaggio:configure-promo-examples', ['--apply' => true])->assertSuccessful();
        $after = $translation->fresh();
        foreach (['title', 'subtitle', 'cta_label', 'cta_url'] as $field) {
            $this->assertSame($before[$field], $after->{$field});
        }
        $slides = $after->payload['watchline']['slides'];
        $this->assertCount(3, $slides);
        $this->assertSame($legacy, $slides[0]);
        $this->assertSame('Mrlje imaju svoje rješenje.', $slides[1]['title']);
        $this->assertStringContainsString('/storage/', $slides[1]['image']);
        $this->assertSame(route('categories.show', 'uklanjanje-mrlja', false), $slides[1]['url']);
        $this->assertSame('Njega za osjetljivo rublje.', $slides[2]['title']);
        $this->assertStringContainsString('attr_lavaggio_fabric=', $slides[2]['url']);
        $unchangedPayload = $after->payload;
        unset($unchangedPayload['watchline']['slides']);
        unset($payload['watchline']['slides']);
        $this->assertSame($payload, $unchangedPayload);
        $secondRunBefore = $after->toArray();
        $this->artisan('lavaggio:configure-promo-examples', ['--apply' => true])->assertSuccessful();
        $this->assertSame($secondRunBefore, $translation->fresh()->toArray());
    }

    public function test_promo_examples_never_replace_an_edited_even_disabled_slide_list(): void
    {
        $translation = $this->campaign()->translations()->sole();
        $payload = $translation->payload;
        $payload['watchline']['slides'] = [['title' => 'Urednička promocija', 'image' => '/moja-slika.webp', 'display' => 'image', 'enabled' => false]];
        $translation->update(['payload' => $payload]);
        $before = $translation->fresh()->toArray();
        $this->artisan('lavaggio:configure-promo-examples', ['--apply' => true])->assertSuccessful();
        $this->assertSame($before, $translation->fresh()->toArray());
    }

    public function test_promo_examples_do_not_write_any_slides_when_a_catalogue_photo_is_missing(): void
    {
        $this->preparePromoExampleCatalogue(false);
        $translation = $this->campaign()->translations()->sole();
        $before = $translation->toArray();
        $this->artisan('lavaggio:configure-promo-examples', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $translation->fresh()->toArray());
    }

    private function preparePromoExampleCatalogue(bool $includeSensitiveImage = true): void
    {
        Storage::fake('public');
        config(['media_profiles.models.'.Product::class.'.collections.product_main.conversions' => []]);
        LavaggioProductFilters::seed();
        $category = Category::create(['code' => 'lavaggio-uklanjanje-mrlja', 'scope' => 'catalog', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'name' => 'Uklanjanje mrlja', 'slug' => 'uklanjanje-mrlja']);
        foreach (['5999109563200' => 'Vanish Oxi Action', '9000101566789' => 'Persil Sensitive'] as $barcode => $name) {
            $product = Product::create(['code' => 'lavaggio-'.$barcode, 'sku' => $barcode, 'is_active' => true, 'stock_qty' => 3, 'base_price' => 10]);
            $product->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => 'proizvod-'.$barcode]);
            if ($includeSensitiveImage || (string) $barcode !== '9000101566789') {
                $product->addMedia(UploadedFile::fake()->image($barcode.'.jpg', 80, 120))->toMediaCollection('product_main', 'public');
            }
        }
    }

    private function campaign(): ContentBlock
    {
        return ContentBlock::where('code', 'lavaggio-home-campaigns')->sole();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);

        return $user;
    }

    private function editor()
    {
        return Livewire::actingAs($this->admin())->test(Form::class, ['blockId' => $this->campaign()->id]);
    }
}
