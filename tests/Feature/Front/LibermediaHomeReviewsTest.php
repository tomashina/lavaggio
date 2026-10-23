<?php

namespace Tests\Feature\Front;

use App\Livewire\Admin\Content\Comment\Manager as CommentManager;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\Support\Comment;
use App\Models\Settings\Local\Language;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LibermediaHomeReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.locale' => 'hr',
            'app.fallback_locale' => 'hr',
            'storefront.theme' => 'herrera',
            'storefront.home' => 'libermedia',
            'storefront_cache.enabled' => false,
        ]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Liber Media',
            'catalog_use_blog' => true,
        ]);
    }

    public function test_home_only_shows_selected_approved_product_reviews_and_their_real_rating(): void
    {
        $product = $this->product('featured-toy');
        $this->review($product, 'Odabrano iskustvo s četiri zvjezdice', ['rating' => 4]);
        $this->review($product, 'Nije odabrano', ['is_featured' => false]);
        $this->review($product, 'Čeka odobrenje', ['status' => Comment::STATUS_PENDING]);
        $this->review($product, 'Odbijen komentar', ['status' => Comment::STATUS_REJECTED]);
        $this->review($product, 'Bez ocjene', ['rating' => null]);
        $deleted = $this->review($product, 'Obrisana recenzija');
        $deleted->delete();
        $parent = $this->review($product, 'Obični komentar', ['is_featured' => false]);
        $this->review($product, 'Odgovor na komentar', ['parent_id' => $parent->id]);
        $inactive = $this->product('inactive-toy', ['is_active' => false]);
        $this->review($inactive, 'Neaktivan proizvod');
        $untranslated = Product::query()->create(['code' => 'no-translation', 'is_active' => true, 'base_price' => 10]);
        $this->review($untranslated, 'Proizvod bez prijevoda');
        $this->review($product, 'Drugi jezik', ['locale' => 'de']);

        $response = $this->get('/')->assertOk()
            ->assertSee('data-libermedia-home-reviews', false)
            ->assertSee('Odabrano iskustvo s četiri zvjezdice')
            ->assertSee('Ana Kupac')
            ->assertSee('Ocjena 4 od 5')
            ->assertSee('data-home-review-rating="4"', false)
            ->assertSee('href="'.route('products.show', ['slug' => 'featured-toy']).'#product-comments"', false)
            ->assertDontSee('private@example.test');

        foreach (['Nije odabrano', 'Čeka odobrenje', 'Odbijen komentar', 'Bez ocjene', 'Obrisana recenzija', 'Odgovor na komentar', 'Neaktivan proizvod', 'Proizvod bez prijevoda', 'Drugi jezik'] as $excluded) {
            $response->assertDontSee($excluded);
        }
        $this->assertSame(1, substr_count($response->getContent(), 'data-home-review-rating='));
        $section = explode('data-libermedia-home-reviews', $response->getContent(), 2)[1];
        $this->assertSame(4, preg_match_all('/<svg\b[^>]*class="[^"]*\bis-filled\b[^"]*"/', $section));
    }

    public function test_all_selected_reviews_appear_in_latest_first_order_between_the_assurance_and_blog_sections(): void
    {
        $block = ContentBlock::query()->create([
            'code' => 'libermedia-home-assurance', 'name' => 'Garancija', 'type' => 'rich_text', 'is_active' => true,
        ]);
        $block->translations()->create(['locale' => 'hr', 'title' => 'Garancija']);
        $block->slots()->create(['placement' => 'home.bottom', 'frontend_variant' => 'desktop', 'is_active' => true]);
        $article = BlogPost::query()->create(['code' => 'home-article', 'is_active' => true, 'published_at' => now()->subDay()]);
        $article->translations()->create(['locale' => 'hr', 'title' => 'Ideje za novi dan', 'slug' => 'home-article']);
        $product = $this->product('home-toy');
        foreach ([4, 3, 2, 1] as $daysAgo) {
            $this->review($product, 'Recenzija stara '.$daysAgo.' dana', ['reviewed_at' => now()->subDays($daysAgo)]);
        }

        $response = $this->get('/')->assertOk()
            ->assertSeeInOrder(['data-libermedia-assurance', 'data-libermedia-home-reviews', 'data-libermedia-home-blog'], false)
            ->assertSeeInOrder(['Recenzija stara 1 dana', 'Recenzija stara 2 dana', 'Recenzija stara 3 dana', 'Recenzija stara 4 dana'])
            ->assertSee('data-libermedia-home-reviews-carousel', false)
            ->assertSee('data-herrera-home-carousel', false)
            ->assertViewHas('featuredReviews', fn ($reviews): bool => $reviews->count() === 4
                && $reviews->every(fn (Comment $review): bool => $review->commentable->relationLoaded('media')));
        $this->assertSame(4, substr_count($response->getContent(), 'data-home-review-rating='));

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $carousel = (new \DOMXPath($document))->query('//*[@data-libermedia-home-reviews-carousel]')->item(0);
        $this->assertNotNull($carousel);
        $options = json_decode($carousel->getAttribute('data-splide'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(3, $options['perPage']);
        $this->assertSame(2, $options['breakpoints'][1023]['perPage']);
        $this->assertSame(1, $options['breakpoints'][767]['perPage']);
    }

    public function test_reviews_show_their_product_thumbnail_from_uploaded_media_or_the_imported_catalog(): void
    {
        Storage::fake('public');
        Storage::fake('home-review-legacy');
        config([
            'legacy_media.enabled' => false,
            'legacy_media.local_root' => Storage::disk('home-review-legacy')->path(''),
        ]);
        $uploadedProduct = $this->product('uploaded-thumb');
        $media = $uploadedProduct->addMedia(UploadedFile::fake()->image('review-product.jpg', 50, 50))
            ->toMediaCollection('product_main');
        $this->review($uploadedProduct, 'Recenzija proizvoda s fotografijom');

        $importedProduct = $this->product('imported-thumb', [
            'payload' => ['opencart' => ['image' => 'catalog/toys/review-imported.jpg']],
        ]);
        Storage::disk('home-review-legacy')->put('catalog/toys/review-imported.jpg', 'local-original');
        $this->review($importedProduct, 'Recenzija uvezenog proizvoda');

        $response = $this->get('/')->assertOk()
            ->assertSee('data-home-review-product-image', false)
            ->assertSee($media->getUrl(), false)
            ->assertSee(asset('image/catalog/toys/review-imported.jpg'), false)
            ->assertSee('loading="lazy"', false);
        $this->assertSame(2, substr_count($response->getContent(), 'data-home-review-product-image'));
    }

    public function test_review_without_a_product_image_keeps_a_working_link_and_an_image_placeholder(): void
    {
        $this->review($this->product('without-image'), 'Recenzija bez fotografije');

        $this->get('/')->assertOk()
            ->assertSee('data-home-review-product-placeholder', false)
            ->assertDontSee('data-home-review-product-image', false)
            ->assertSee('href="'.route('products.show', ['slug' => 'without-image']).'#product-comments"', false)
            ->assertSee('Igračka without-image');
    }

    public function test_no_reviews_are_automatically_featured_and_other_storefronts_do_not_show_the_block(): void
    {
        $product = $this->product('regular-toy');
        $comment = $this->review($product, 'Još nije odabrana recenzija', ['is_featured' => false]);
        $this->get('/')->assertOk()->assertDontSee('data-libermedia-home-reviews', false);
        $comment->update(['is_featured' => true]);
        config(['storefront.home' => 'herrera']);
        $this->get('/')->assertOk()->assertDontSee('data-libermedia-home-reviews', false);
    }

    public function test_requested_language_and_fallback_have_working_product_links(): void
    {
        Language::query()->create(['code' => 'en', 'locale' => 'en', 'name' => 'English', 'is_active' => true, 'is_default' => true]);
        $bilingual = $this->product('bilingual-toy');
        $bilingual->translations()->create(['locale' => 'en', 'name' => 'English toy', 'slug' => 'english-toy']);
        $fallback = $this->product('fallback-toy');
        $this->review($bilingual, 'English review', ['locale' => 'en']);
        $this->review($fallback, 'Hrvatsko iskustvo');

        $this->withSession(['front_locale' => 'en'])->get('/')->assertOk()
            ->assertSee('English review')->assertSee('English toy')->assertSee('Hrvatsko iskustvo')
            ->assertSee('href="'.route('products.show', ['slug' => 'english-toy']).'#product-comments"', false)
            ->assertSee('href="'.route('products.show', ['slug' => 'fallback-toy']).'#product-comments"', false);
    }

    public function test_admin_can_select_and_remove_a_review_using_the_homepage_switch(): void
    {
        $admin = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($admin);
        $comment = $this->review($this->product('admin-selected-toy'), 'Odabrano kroz administraciju', ['is_featured' => false]);

        Livewire::actingAs($admin)->test(CommentManager::class)
            ->call('edit', $comment->id)
            ->assertSee('Prikaži na naslovnici')
            ->set('editForm.is_featured', true)
            ->call('saveEdit')->assertHasNoErrors();
        $this->get('/')->assertOk()->assertSee('Odabrano kroz administraciju');

        Livewire::actingAs($admin)->test(CommentManager::class)
            ->call('edit', $comment->id)
            ->set('editForm.is_featured', false)
            ->call('saveEdit')->assertHasNoErrors();
        $this->get('/')->assertOk()->assertDontSee('data-libermedia-home-reviews', false);
    }

    private function product(string $code, array $attributes = []): Product
    {
        $product = Product::query()->create(array_merge([
            'code' => $code, 'sku' => $code, 'is_active' => true, 'base_price' => 10, 'stock_qty' => 10,
        ], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => 'Igračka '.$code, 'slug' => $code]);

        return $product;
    }

    private function review(Product $product, string $body, array $attributes = []): Comment
    {
        return $product->comments()->create(array_merge([
            'author_name' => 'Ana Kupac', 'author_email' => 'private@example.test',
            'body' => $body, 'rating' => 5, 'status' => Comment::STATUS_APPROVED,
            'is_featured' => true, 'locale' => 'hr', 'reviewed_at' => now(),
        ], $attributes));
    }
}
