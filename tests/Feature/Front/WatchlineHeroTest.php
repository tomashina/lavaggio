<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Models\Content\Support\Comment;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Services\Front\WatchlineHeroService;
use App\Services\Pricing\ProductPricePresentationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class WatchlineHeroTest extends TestCase
{
    use RefreshDatabase;

    private Category $women;

    private Category $cluseCategory;

    private Category $men;

    private Manufacturer $cluse;

    private OrderStatus $complete;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.name' => 'Watchline', 'storefront.theme' => 'herrera', 'storefront.home' => 'watchline', 'storefront.brand' => 'watchline', 'commerce.b2c_only' => true,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
        ]);
        $this->women = Category::create(['code' => 'herrera-oc-category-63', 'scope' => 'catalog', 'is_active' => true]);
        $this->women->translations()->create(['locale' => 'hr', 'scope' => 'catalog', 'name' => 'Ženski satovi', 'slug' => 'zenski-satovi']);
        $this->cluseCategory = Category::create(['code' => 'herrera-oc-category-78', 'scope' => 'catalog', 'is_active' => true, 'parent_id' => $this->women->id]);
        $this->men = Category::create(['code' => 'herrera-oc-category-64', 'scope' => 'catalog', 'is_active' => true]);
        Category::fixTree();
        $this->cluse = Manufacturer::create(['code' => 'test-cluse', 'is_active' => true]);
        $this->cluse->translations()->create(['locale' => 'hr', 'name' => 'CLUSE', 'slug' => 'cluse']);
        $this->complete = OrderStatus::create(['code' => 'herrera-oc-status-5', 'name' => 'Complete', 'is_paid' => false, 'is_cancelled' => false]);
    }

    public function test_completed_purchase_quantities_determine_rank_without_inflation_from_other_statuses_or_brands(): void
    {
        $best = $this->product(1, ['stock_qty' => 0]);
        $second = $this->product(2);
        $reviewedTie = $this->product(3);
        $unreviewedTie = $this->product(4);
        $this->sale($best, 4);
        $this->sale($best, 3);
        $this->sale($second, 6);
        $this->sale($reviewedTie, 5);
        $this->sale($unreviewedTie, 5);
        $this->review($reviewedTie, 'Odličan sat.');
        foreach ([null, OrderStatus::create(['code' => 'pending', 'name' => 'Pending']), OrderStatus::create(['code' => 'cancelled', 'name' => 'Canceled', 'is_paid' => true, 'is_cancelled' => true]), OrderStatus::create(['code' => 'refunded', 'name' => 'Refunded'])] as $status) {
            $this->sale($unreviewedTie, 100, $status?->id, false);
        }
        $men = $this->product(5);
        $men->categories()->sync([$this->men->id]);
        $otherBrand = $this->product(6, ['manufacturer_id' => null]);
        $inactive = $this->product(7, ['is_active' => false]);
        $noImage = $this->product(8, ['payload' => ['opencart' => ['image' => 'javascript:invalid']]]);
        foreach ([$men, $otherBrand, $inactive, $noImage] as $excluded) {
            $this->sale($excluded, 999);
        }

        $this->assertSame([$best->id, $second->id, $reviewedTie->id, $unreviewedTie->id], app(WatchlineHeroService::class)->products()->pluck('id')->all());
    }

    public function test_hero_uses_all_approved_croatian_ratings_without_review_text_or_authors(): void
    {
        $product = $this->product(1);
        $withoutReview = $this->product(2, ['stock_qty' => 0]);
        $this->sale($product, 5);
        $this->sale($withoutReview, 4);
        $privateUser = User::factory()->create(['name' => 'Privatno ime računa', 'email' => 'private@example.test']);
        $parent = $this->review($product, 'Stvarna odobrena recenzija', ['author_name' => 'Javno ime', 'user_id' => $privateUser->id, 'rating' => 4, 'is_featured' => true]);
        $this->review($product, 'Druga odobrena recenzija', ['rating' => 5]);
        $this->review($product, 'Neodobrena recenzija', ['status' => Comment::STATUS_PENDING]);
        $this->review($product, 'Obrisana recenzija')->delete();
        $this->review($product, 'Drugi jezik', ['locale' => 'en', 'rating' => 1]);
        $this->review($product, 'Odgovor na recenziju', ['parent_id' => $parent->id]);
        config(['app.fallback_locale' => 'en']);
        $this->fixedPrice();

        $slides = app(WatchlineHeroService::class)->slides();

        $this->assertSame(2, $slides[0]['rating_count']);
        $this->assertSame(4.5, $slides[0]['rating_average']);
        $this->assertSame(0, $slides[1]['rating_count']);
        $this->assertNull($slides[1]['rating_average']);
        $this->assertArrayNotHasKey('review', $slides[0]);
        $this->assertArrayNotHasKey('description', $slides[0]);
        $html = view('front.partials.watchline-hero', ['watchlineHeroSlides' => $slides])->render();
        $this->assertStringContainsString('(2 recenzije)', $html);
        $this->assertStringContainsString('Prosječna ocjena 4,5 od 5; 2 recenzije', $html);
        $this->assertStringContainsString(route('products.show', ['slug' => 'hero-watch-1']).'#product-comments', $html);
        $this->assertGreaterThan(strpos($html, 'class="wl-hero-title"'), strpos($html, 'class="wl-hero-rating"'));
        $this->assertLessThan(strpos($html, 'class="wl-hero-price"'), strpos($html, 'class="wl-hero-rating"'));
        $this->assertSame(1, substr_count($html, 'class="wl-hero-rating"'));
        $this->assertSame(4, substr_count($html, 'class="wl-hero-star is-full"'));
        $this->assertSame(1, substr_count($html, 'class="wl-hero-star is-half"'));
        $this->assertStringNotContainsString('Stvarna odobrena recenzija', $html);
        $this->assertStringNotContainsString('Javno ime', $html);
        $this->assertStringNotContainsString('Privatno ime računa', $html);
        $this->assertStringNotContainsString('private@example.test', $html);
        $this->assertStringNotContainsString('Neodobrena recenzija', $html);
        $this->assertStringNotContainsString('Izvorni opis sata.', $html);
        $this->assertStringNotContainsString('Na zalihi', $html);
        $this->assertStringNotContainsString('Provjerite rok isporuke', $html);
        $this->assertDatabaseCount('content_comments', 6);
    }

    public function test_hero_has_six_real_images_shared_prices_and_accessible_motion_controls(): void
    {
        foreach (range(1, 9) as $index) {
            $this->sale($this->product($index), 10 - $index);
        }
        $this->fixedPrice();
        $service = app(WatchlineHeroService::class);
        $products = $service->products();
        $slides = $service->slides(products: $products);

        $this->assertCount(8, $products);
        $this->assertCount(6, $slides);
        $this->assertSame('49,90 €', $slides[0]['price']);
        $this->assertSame('FLUETTE', $slides[0]['title']);
        $this->assertSame('CW1', $slides[0]['model']);
        $this->assertSame('79,90 €', $slides[0]['old_price']);
        $this->assertSame('https://www.watchline.hr/image/catalog/cluse-1.jpg', $slides[0]['image']);
        $html = view('front.partials.watchline-hero', ['watchlineHeroSlides' => $slides])->render();
        $this->assertSame(6, substr_count($html, 'data-watchline-hero-product='));
        $this->assertStringContainsString('data-watchline-hero-toggle', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('"reducedMotion":{"speed":0,"rewindSpeed":0,"autoplay":"pause"}', $html);
        $this->assertStringContainsString('"pauseOnFocus":true', $html);
        $this->assertStringContainsString('loading="eager" fetchpriority="high"', $html);
    }

    public function test_no_completed_cluse_purchases_produces_no_invented_bestsellers_or_reviews(): void
    {
        $this->product(1);
        $slides = app(WatchlineHeroService::class)->slides();
        $this->assertCount(0, $slides);
        $html = view('front.partials.watchline-hero', ['watchlineHeroSlides' => $slides])->render();
        $this->assertStringNotContainsString('NAJPRODAVANIJI CLUSE SATOVI', $html);
        $this->assertStringNotContainsString('wl-hero-rating', $html);
        $this->assertStringContainsString('Istražite ponudu', $html);
    }

    public function test_home_opens_with_promotions_and_keeps_the_ranked_cluse_carousel_without_legacy_hero_or_categories(): void
    {
        $this->app['env'] = 'local';
        foreach (range(1, 9) as $index) {
            $this->sale($this->product($index), 10 - $index);
        }
        $this->artisan('watchline:configure-home')->assertSuccessful();
        foreach (['home.hero' => 'Uklonjeni glavni slider', 'home.categories' => 'Uklonjeni teaser kategorija'] as $placement => $title) {
            $block = ContentBlock::create(['code' => 'old-'.$placement, 'name' => $title, 'type' => 'rich_text', 'is_active' => true]);
            $block->translations()->create(['locale' => 'hr', 'title' => $title, 'body_html' => '<p>'.$title.'</p>']);
            $block->slots()->create(['placement' => $placement, 'frontend_variant' => 'desktop', 'is_active' => true]);
        }

        $this->get('/')->assertOk()
            ->assertViewMissing('watchlineHeroSlides')
            ->assertSee('data-watchline-widget="watchline-home-cluse"', false)
            ->assertSeeInOrder(['data-libermedia-campaigns', 'data-watchline-cluse-products'], false)
            ->assertSee('CLUSE, po izboru naših kupaca')->assertSee('Svi CLUSE satovi')
            ->assertDontSee('data-watchline-hero', false)->assertDontSee('data-watchline-categories', false)
            ->assertDontSee('front-theme/scripts/watchline-hero.js', false)
            ->assertDontSee('Uklonjeni glavni slider')->assertDontSee('Uklonjeni teaser kategorija')
            ->assertDontSee('Pronađite svoj detalj.')->assertDontSee('WATCHLINE. VAŠ TRENUTAK.')
            ->assertDontSee('Pronađite svoj novi omiljeni detalj');
    }

    private function product(int $index, array $fields = []): Product
    {
        $product = Product::create(array_replace(['code' => 'hero-watch-'.$index, 'sku' => 'CW'.$index, 'manufacturer_id' => $this->cluse->id, 'is_active' => true, 'stock_qty' => 3, 'base_price' => 129, 'payload' => ['opencart' => ['model' => 'CW'.$index, 'image' => 'catalog/cluse-'.$index.'.jpg']]], $fields));
        $product->translations()->create(['locale' => 'hr', 'name' => 'Ženski sat CLUSE FLUETTE CW'.$index, 'slug' => 'hero-watch-'.$index, 'description' => '<p>Izvorni opis sata.</p>']);
        $product->categories()->attach($this->cluseCategory);

        return $product;
    }

    private function sale(Product $product, int $quantity, ?int $status = null, bool $complete = true): void
    {
        $order = DB::table('orders')->insertGetId(['order_number' => 'HERO-'.DB::table('orders')->count(), 'status_id' => $complete ? $this->complete->id : $status, 'customer_name' => 'Test', 'customer_email' => 'test@example.test']);
        DB::table('order_items')->insert(['order_id' => $order, 'product_id' => $product->id, 'quantity' => $quantity, 'name' => 'Sat']);
    }

    private function review(Product $product, string $body, array $fields = []): Comment
    {
        return Comment::create(array_replace(['commentable_type' => Product::class, 'commentable_id' => $product->id, 'author_name' => 'Kupac', 'locale' => 'hr', 'body' => $body, 'rating' => 5, 'status' => Comment::STATUS_APPROVED], $fields));
    }

    private function fixedPrice(): void
    {
        $this->mock(ProductPricePresentationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forProduct')->andReturn(['can_view_price' => true, 'display_current' => 49.9, 'display_old' => 79.9]);
        });
    }
}
