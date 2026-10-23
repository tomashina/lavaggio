<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\Content\Support\Comment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WatchlineContentWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.name' => 'Watchline', 'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'storefront.theme' => 'herrera', 'storefront.home' => 'watchline', 'storefront.brand' => 'watchline',
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
        ]);
    }

    public function test_brands_widget_uses_editor_selection_order_and_editable_heading_link_and_visible_count(): void
    {
        $first = $this->manufacturer('brand-first');
        $second = $this->manufacturer('brand-second');
        $unselected = $this->manufacturer('brand-unselected');
        $inactive = $this->manufacturer('brand-inactive', ['is_active' => false]);
        $withoutLogo = $this->manufacturer('brand-no-logo', ['payload' => []]);
        $block = $this->block('watchline_brands', ['watchline' => ['eyebrow' => 'Odabir urednika', 'visible_count' => 4, 'source' => 'manual']]);
        foreach ([$second, $first, $inactive, $withoutLogo] as $index => $manufacturer) {
            $block->items()->create(['item_type' => 'manufacturer', 'item_id' => $manufacturer->id, 'sort_order' => $index]);
        }

        $html = $this->renderBlock($block);
        $this->assertSame([$second->id, $first->id], $this->attributeValues($html, 'data-watchline-brand-id'));
        $this->assertStringNotContainsString('data-watchline-brand-id="'.$unselected->id.'"', $html);
        $this->assertStringContainsString('Naslov iz widgeta', $html);
        $this->assertStringContainsString('Podnaslov iz widgeta', $html);
        $this->assertStringContainsString('Odabir urednika', $html);
        $this->assertStringContainsString('href="/uredjeni-brandovi"', $html);
        $this->assertStringContainsString('Pogledajte izbor', $html);
        $this->assertStringContainsString('id="watchline-brands-title-'.$block->id.'"', $html);
        $this->assertStringContainsString('data-watchline-brand-columns="2"', $html);
    }

    public function test_empty_manual_brand_selection_does_not_fall_back_to_all_and_all_source_uses_only_active_brands_with_products(): void
    {
        $active = $this->manufacturer('brand-active');
        $empty = $this->manufacturer('brand-empty', [], false);
        $inactive = $this->manufacturer('brand-disabled', ['is_active' => false]);
        $block = $this->block('watchline_brands', ['watchline' => ['source' => 'manual']]);
        $this->assertStringNotContainsString('data-watchline-brands-widget', $this->renderBlock($block));
        $block->translations()->where('locale', 'hr')->update(['payload' => ['watchline' => ['source' => 'all']]]);

        $ids = $this->attributeValues($this->renderBlock($block), 'data-watchline-brand-id');
        $this->assertSame([$active->id], $ids);
        $this->assertNotContains($empty->id, $ids);
        $this->assertNotContains($inactive->id, $ids);
    }

    public function test_reviews_widget_keeps_all_approved_ratings_and_excludes_private_or_unavailable_records(): void
    {
        $product = $this->product('review-product');
        $this->review($product, 'Odobrena ocjena četiri', ['rating' => 4]);
        $this->review($product, 'Odobrena ocjena pet', ['rating' => 5, 'is_featured' => true]);
        $this->review($product, 'Odobrena ocjena jedan', ['rating' => 1]);
        $pending = $this->review($product, 'Skrivena recenzija na čekanju', ['status' => Comment::STATUS_PENDING]);
        $this->review($product, 'Skriveni odgovor', ['parent_id' => $pending->id]);
        $this->review($product, 'Skriveni drugi jezik', ['locale' => 'en']);
        $this->review($product, 'Skrivena obrisana recenzija')->delete();
        $this->review($this->product('inactive-review-product', ['is_active' => false]), 'Skriveni neaktivni proizvod');
        $noSlug = $this->product('review-without-slug');
        $noSlug->translations()->update(['slug' => '']);
        $this->review($noSlug, 'Skriveni proizvod bez linka');
        $block = $this->block('watchline_reviews', ['items_limit' => 50, 'reviews_featured_only' => false, 'watchline' => ['eyebrow' => 'Iskustva iz widgeta']]);

        $html = $this->renderBlock($block);
        foreach (['Odobrena ocjena četiri', 'Odobrena ocjena pet', 'Odobrena ocjena jedan', 'Iskustva iz widgeta', 'Naslov iz widgeta', 'Podnaslov iz widgeta'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('Skriven', $html);
        $this->assertSame(3, substr_count($html, 'data-home-review-rating='));
        $this->assertStringContainsString('href="/uredjeni-brandovi"', $html);
        $this->assertStringContainsString('id="watchline-reviews-title-'.$block->id.'"', $html);
    }

    public function test_reviews_widget_obeys_featured_filter_and_limit(): void
    {
        $product = $this->product('featured-review-product');
        $this->review($product, 'Starija izdvojena recenzija', ['is_featured' => true, 'rating' => 4]);
        $this->review($product, 'Najnovija izdvojena recenzija', ['is_featured' => true, 'rating' => 3]);
        $this->review($product, 'Neizdvojena novija recenzija');
        $block = $this->block('watchline_reviews', ['items_limit' => 1, 'reviews_featured_only' => true]);

        $html = $this->renderBlock($block);
        $this->assertStringContainsString('Najnovija izdvojena recenzija', $html);
        $this->assertStringNotContainsString('Starija izdvojena recenzija', $html);
        $this->assertStringNotContainsString('Neizdvojena novija recenzija', $html);
        $this->assertSame(1, substr_count($html, 'data-home-review-rating='));
    }

    public function test_repeated_widgets_keep_distinct_accessible_headings_and_reject_unsafe_cta_urls(): void
    {
        $this->review($this->product('repeated-review-product'), 'Javna recenzija');
        $first = $this->block('watchline_reviews');
        $second = $this->block('watchline_reviews');
        $second->translations()->update(['title' => 'Drugi naslov iz widgeta', 'cta_url' => 'javascript:alert(1)']);

        $html = $this->renderBlock($first).$this->renderBlock($second);
        $this->assertSame(1, substr_count($html, 'id="watchline-reviews-title-'.$first->id.'"'));
        $this->assertSame(1, substr_count($html, 'id="watchline-reviews-title-'.$second->id.'"'));
        $this->assertStringContainsString('aria-labelledby="watchline-reviews-title-'.$first->id.'"', $html);
        $this->assertStringContainsString('aria-labelledby="watchline-reviews-title-'.$second->id.'"', $html);
        $this->assertStringContainsString('Drugi naslov iz widgeta', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_blog_widget_uses_latest_published_articles_and_featured_source_with_editable_copy(): void
    {
        $older = $this->blogPost('older-post', ['published_at' => now()->subDays(4), 'is_featured' => true]);
        $newer = $this->blogPost('newer-post', ['published_at' => now()->subDay()]);
        $this->blogPost('future-post', ['published_at' => now()->addDay()]);
        $this->blogPost('draft-post', ['published_at' => null]);
        $this->blogPost('inactive-post', ['is_active' => false]);
        $this->blogPost('no-slug-post')->translations()->update(['slug' => '']);
        $block = $this->block('watchline_blog', ['items_limit' => 2, 'blog_source' => 'latest', 'watchline' => ['eyebrow' => 'Članci iz widgeta']]);

        $html = $this->renderBlock($block);
        $this->assertSame([$newer->id, $older->id], $this->attributeValues($html, 'data-watchline-blog-id'));
        foreach (['Naslov iz widgeta', 'Podnaslov iz widgeta', 'Članci iz widgeta', 'Pogledajte izbor'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('href="/uredjeni-brandovi"', $html);
        $this->assertStringContainsString('id="watchline-blog-title-'.$block->id.'"', $html);
        $block->translations()->where('locale', 'hr')->update(['payload' => ['items_limit' => 2, 'blog_source' => 'featured']]);
        $this->assertSame([$older->id], $this->attributeValues($this->renderBlock($block), 'data-watchline-blog-id'));
    }

    public function test_manual_blog_selection_keeps_editor_order_overrides_automatic_source_and_never_shows_future_posts(): void
    {
        $first = $this->blogPost('manual-first', ['published_at' => now()->subDays(3)]);
        $second = $this->blogPost('manual-second', ['published_at' => now()->subDay()]);
        $future = $this->blogPost('manual-future', ['published_at' => now()->addDay()]);
        $this->blogPost('not-selected', ['is_featured' => true]);
        $block = $this->block('watchline_blog', ['items_limit' => 5, 'blog_source' => 'featured']);
        foreach ([$first, $future, $second] as $index => $post) {
            $block->items()->create(['item_type' => 'blog', 'item_id' => $post->id, 'sort_order' => $index]);
        }

        $this->assertSame([$first->id, $second->id], $this->attributeValues($this->renderBlock($block), 'data-watchline-blog-id'));
    }

    private function block(string $type, array $payload = []): ContentBlock
    {
        $block = ContentBlock::create(['code' => 'test-'.$type.'-'.ContentBlock::count(), 'name' => 'Interni naziv', 'type' => $type, 'is_active' => true]);
        $block->translations()->create([
            'locale' => 'hr', 'title' => 'Naslov iz widgeta', 'subtitle' => 'Podnaslov iz widgeta',
            'cta_label' => 'Pogledajte izbor', 'cta_url' => '/uredjeni-brandovi', 'payload' => $payload,
        ]);

        return $block;
    }

    private function renderBlock(ContentBlock $block): string
    {
        $block = $block->fresh(['items', 'translations', 'media']);

        return view('components.content-placement', ['items' => collect([[
            'block' => $block, 'translation' => $block->translations->firstWhere('locale', 'hr'), 'slot' => new ContentBlockSlot,
        ]]), 'storeSettings' => []])->render();
    }

    private function attributeValues(string $html, string $name): array
    {
        preg_match_all('/'.preg_quote($name, '/').'="([0-9]+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    private function manufacturer(string $code, array $attributes = [], bool $withProducts = true): Manufacturer
    {
        $manufacturer = Manufacturer::create(array_replace(['code' => $code, 'is_active' => true, 'payload' => ['opencart' => ['image' => 'catalog/brands/'.$code.'.svg']]], $attributes));
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        if ($withProducts) {
            $this->product('product-'.$code, ['manufacturer_id' => $manufacturer->id]);
        }

        return $manufacturer;
    }

    private function product(string $slug, array $attributes = []): Product
    {
        $product = Product::create(array_replace(['code' => $slug, 'sku' => $slug, 'is_active' => true, 'stock_qty' => 2, 'base_price' => 100], $attributes));
        $product->translations()->create(['locale' => 'hr', 'name' => $slug, 'slug' => $slug]);

        return $product;
    }

    private function review(Product $product, string $body, array $attributes = []): Comment
    {
        return Comment::create(array_replace([
            'commentable_type' => Product::class, 'commentable_id' => $product->id,
            'locale' => 'hr', 'author_name' => 'Javno ime', 'body' => $body, 'rating' => 5,
            'status' => Comment::STATUS_APPROVED,
        ], $attributes));
    }

    private function blogPost(string $slug, array $attributes = []): BlogPost
    {
        $post = BlogPost::create(array_replace(['code' => $slug, 'is_active' => true, 'published_at' => now()->subDay()], $attributes));
        $post->translations()->create(['locale' => 'hr', 'title' => $slug, 'slug' => $slug]);

        return $post;
    }
}
