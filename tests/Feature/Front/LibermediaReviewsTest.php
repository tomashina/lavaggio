<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LibermediaReviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_review_summary_uses_only_approved_ratings_for_the_current_language(): void
    {
        config(['commerce.b2c_only' => true]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Liber Media');
        $product = Product::create(['code' => 'review-product', 'sku' => 'review-product', 'base_price' => 10, 'stock_qty' => 10, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Igračka', 'slug' => 'review-product', 'description' => '<p>Opis igračke.</p>']);
        foreach ([[5, 'approved', 'hr', 'Odlična igračka'], [4, 'approved', 'hr', 'Lijep komplet'], [1, 'pending', 'hr', 'Privatni komentar'], [1, 'approved', 'de', 'Anderer Kommentar']] as [$rating, $status, $locale, $body]) {
            $product->comments()->create(['author_name' => 'Kupac', 'body' => $body, 'rating' => $rating, 'status' => $status, 'locale' => $locale]);
        }
        $this->get(route('products.show', 'review-product'))->assertOk()
            ->assertSee('4,5')->assertSee('Prosječna ocjena kupaca')->assertSee('Raspodjela ocjena')
            ->assertSee('Ocjena 5 od 5')->assertSee('Ocjena 4 od 5')
            ->assertSee('Odlična igračka')->assertDontSee('Privatni komentar')->assertDontSee('Anderer Kommentar');
    }

    public function test_products_without_ratings_show_an_honest_empty_state(): void
    {
        config(['commerce.b2c_only' => true]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Liber Media');
        $product = Product::create(['code' => 'no-reviews', 'sku' => 'no-reviews', 'base_price' => 10, 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Novi proizvod', 'slug' => 'no-reviews']);
        $this->get(route('products.show', 'no-reviews'))->assertOk()->assertSee('Još nema ocjena. Podijelite svoje iskustvo.');
    }
}
