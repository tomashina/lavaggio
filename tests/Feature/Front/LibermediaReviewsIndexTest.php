<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Support\Comment;
use App\Models\Settings\Local\Language;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibermediaReviewsIndexTest extends TestCase
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
        app(SystemSettingsService::class)->put('store_brand_name', 'Liber Media');
    }

    public function test_guests_can_read_all_approved_public_product_reviews_including_unfeatured_reviews(): void
    {
        $product = $this->product('public-toy');
        $this->review($product, 'Objavljena recenzija koja nije na naslovnici');
        $this->review($product, 'Odabrana recenzija s naslovnice', ['is_featured' => true]);
        $parent = $this->review($product, 'Recenzija s odgovorom');
        $this->review($product, 'Privatni odgovor na recenziju', ['parent_id' => $parent->id]);
        foreach ([Comment::STATUS_PENDING, Comment::STATUS_REJECTED, Comment::STATUS_SPAM] as $status) {
            $this->review($product, 'Skriveni status '.$status, ['status' => $status]);
        }
        foreach ([null, 0, 6] as $rating) {
            $this->review($product, 'Nevaljana ocjena '.($rating ?? 'null'), ['rating' => $rating]);
        }
        $this->review($product, 'Obrisana recenzija')->delete();
        $this->review($this->product('inactive-toy', ['is_active' => false]), 'Neaktivan proizvod');
        $untranslated = Product::query()->create(['code' => 'untranslated', 'is_active' => true, 'base_price' => 10]);
        $this->review($untranslated, 'Proizvod bez prijevoda');
        $emptySlug = $this->product('empty-slug');
        $emptySlug->translations()->update(['slug' => '']);
        $this->review($emptySlug, 'Proizvod bez poveznice');
        $article = BlogPost::query()->create(['code' => 'article', 'is_active' => true]);
        $article->comments()->create([
            'author_name' => 'Autor blog komentara', 'body' => 'Komentar bloga', 'rating' => 5,
            'status' => Comment::STATUS_APPROVED, 'locale' => 'hr',
        ]);

        $response = $this->get(route('reviews.index'))->assertOk()
            ->assertSee('Što kažu naši kupci')
            ->assertSee('Objavljena recenzija koja nije na naslovnici')
            ->assertSee('Odabrana recenzija s naslovnice')
            ->assertSee('Recenzija s odgovorom')
            ->assertSee('data-reviews-total="3"', false)
            ->assertDontSee('private@example.test');

        foreach (['Privatni odgovor na recenziju', 'Skriveni status', 'Nevaljana ocjena', 'Obrisana recenzija', 'Neaktivan proizvod', 'Proizvod bez prijevoda', 'Proizvod bez poveznice', 'Komentar bloga'] as $excluded) {
            $response->assertDontSee($excluded);
        }
        $this->assertSame(3, substr_count($response->getContent(), 'data-home-review-rating='));
    }

    public function test_reviews_are_paginated_latest_first_and_summary_includes_every_page(): void
    {
        $this->travelTo(now()->startOfDay());
        $product = $this->product('many-reviews');
        foreach (range(1, 31) as $number) {
            $this->review($product, 'Recenzija broj '.$number.'.', [
                'rating' => $number === 1 ? 1 : 5,
                'reviewed_at' => now()->subDays(32 - $number),
            ]);
        }

        $firstPage = $this->get(route('reviews.index', ['source' => 'home']))->assertOk()
            ->assertSeeInOrder(['Recenzija broj 31.', 'Recenzija broj 30.', 'Recenzija broj 2.'])
            ->assertDontSee('Recenzija broj 1.')
            ->assertSee('data-reviews-total="31"', false)
            ->assertSee('data-reviews-average="4.87"', false)
            ->assertSee('4,9')
            ->assertSee('Sljedeća')
            ->assertViewHas('reviews', fn ($reviews): bool => $reviews->perPage() === 30
                && $reviews->total() === 31 && $reviews->count() === 30
                && str_contains($reviews->nextPageUrl(), 'source=home'));
        $this->assertSame(30, substr_count($firstPage->getContent(), 'data-home-review-rating='));

        $secondPage = $this->get(route('reviews.index', ['page' => 2]))->assertOk()
            ->assertSee('Recenzija broj 1.')
            ->assertDontSee('Recenzija broj 2.')
            ->assertSee('data-reviews-total="31"', false)
            ->assertSee('data-reviews-average="4.87"', false)
            ->assertSee('Prethodna');
        $this->assertSame(1, substr_count($secondPage->getContent(), 'data-home-review-rating='));

        $this->get(route('reviews.index', ['page' => 3]))->assertOk()
            ->assertSee('Na ovoj stranici nema recenzija')
            ->assertSee('Prva stranica recenzija')
            ->assertDontSee('Još nema objavljenih recenzija');
    }

    public function test_product_images_and_product_review_links_are_kept_without_exposing_unescaped_content(): void
    {
        Storage::fake('public');
        $product = $this->product('photographed-toy');
        $product->addMedia(UploadedFile::fake()->image('review-toy.jpg', 50, 50))
            ->toMediaCollection('product_main');
        $this->review($product, str_repeat('Dobar proizvod. ', 30).'<script>alert("review")</script> Cijela recenzija je vidljiva');
        $this->review($this->product('toy-without-image'), 'Iskustvo proizvoda bez slike');

        $response = $this->get(route('reviews.index'))->assertOk()
            ->assertSee('data-home-review-product-image', false)
            ->assertSee('data-home-review-product-placeholder', false)
            ->assertSee('href="'.route('products.show', ['slug' => 'photographed-toy']).'#product-comments"', false)
            ->assertSee('Cijela recenzija je vidljiva')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("review")</script>', false)
            ->assertViewHas('reviews', fn ($reviews): bool => $reviews->every(fn (Comment $review): bool => $review->relationLoaded('user')
                && $review->commentable->relationLoaded('media')
                && $review->commentable->relationLoaded('translations')));

        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $images = (new \DOMXPath($document))->query('//a[@href="'.route('products.show', ['slug' => 'photographed-toy']).'#product-comments"]//*[@data-home-review-product-image]');
        $this->assertCount(1, $images);
        $this->assertStringContainsString('review-toy', $images->item(0)->getAttribute('src'));
    }

    public function test_requested_language_and_fallback_are_included_in_the_list_and_summary(): void
    {
        Language::query()->create(['code' => 'en', 'locale' => 'en', 'name' => 'English', 'is_active' => true, 'is_default' => true]);
        $bilingual = $this->product('bilingual-toy');
        $bilingual->translations()->create(['locale' => 'en', 'name' => 'English toy', 'slug' => 'english-toy']);
        $fallback = $this->product('fallback-toy');
        $this->review($bilingual, 'English review', ['locale' => 'en', 'rating' => 4]);
        $this->review($fallback, 'Hrvatsko iskustvo', ['rating' => 2]);
        $this->review($bilingual, 'Deutsche Rezension', ['locale' => 'de', 'rating' => 5]);

        $this->withSession(['front_locale' => 'en'])->get(route('reviews.index'))->assertOk()
            ->assertSee('English review')->assertSee('English toy')->assertSee('Hrvatsko iskustvo')
            ->assertDontSee('Deutsche Rezension')
            ->assertSee('data-reviews-total="2"', false)
            ->assertSee('data-reviews-average="3.00"', false)
            ->assertSee('href="'.route('products.show', ['slug' => 'english-toy']).'#product-comments"', false)
            ->assertSee('href="'.route('products.show', ['slug' => 'fallback-toy']).'#product-comments"', false);
    }

    public function test_empty_reviews_page_does_not_invent_an_average_or_show_unapproved_reviews(): void
    {
        $this->review($this->product('pending-toy'), 'Još neodobreno iskustvo', ['status' => Comment::STATUS_PENDING]);

        $this->get(route('reviews.index'))->assertOk()
            ->assertSee('Još nema objavljenih recenzija')
            ->assertSee('href="'.route('shop.index').'"', false)
            ->assertDontSee('Još neodobreno iskustvo')
            ->assertDontSee('data-reviews-average', false)
            ->assertDontSee('data-home-review-rating', false);
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
            'is_featured' => false, 'locale' => 'hr', 'reviewed_at' => now(),
        ], $attributes));
    }
}
