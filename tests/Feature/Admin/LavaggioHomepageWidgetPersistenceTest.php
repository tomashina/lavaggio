<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Block\Form;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Content\Support\Comment;
use App\Models\User;
use App\Support\WatchlineWidgetSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LavaggioHomepageWidgetPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'local';
        config([
            'storefront.home' => 'lavaggio', 'storefront.theme' => 'herrera',
            'app.locale' => 'hr', 'app.fallback_locale' => 'hr',
            'commerce.b2c_only' => true, 'commerce.b2b_only' => false,
            'storefront_cache.enabled' => false,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.watchline.hr',
        ]);
        $this->artisan('lavaggio:configure-home')->assertSuccessful();
        $this->admin = User::factory()->create();
        Bouncer::assign('admin')->to($this->admin);
    }

    public function test_category_copy_links_icons_and_uploaded_photo_reach_the_homepage_after_save(): void
    {
        Storage::fake('public');
        $block = $this->block('categories');
        $this->get('/')->assertOk();

        $this->editor($block)
            ->assertDontSeeHtml('wire:model="form.subtitle"')
            ->set('form.title', 'Sve za naš dom')
            ->set('form.cta_label', 'Cijeli novi izbor')->set('form.cta_url', '/shop?izbor=novi')
            ->set('form.watchline.eyebrow', 'Novi mali naslov')
            ->set('form.watchline.card_1_title', 'Mirisna njega')
            ->set('form.watchline.card_1_url', '/shop?njega=mirisna')
            ->set('form.watchline.card_1_icon', 'heart')
            ->set('form.watchline.card_1_image_alt', 'Fotografija mirisne njege')
            ->set('watchlineImageUploads.card_1_image', UploadedFile::fake()->image('njega.png', 800, 600))
            ->set('form.watchline.card_6_title', 'Naši novi kompleti')
            ->set('form.watchline.card_6_url', '/bundle-artikli?izbor=novi')
            ->set('form.watchline.card_6_cta_label', 'Odaberi komplet')
            ->call('save')->assertHasNoErrors();

        $url = data_get($block->fresh()->translations()->sole()->payload, 'watchline.card_1_image');
        Storage::disk('public')->assertExists(substr($url, strlen('/storage/')));
        $html = $this->widgetHtml($block);
        foreach (['Sve za naš dom', 'Cijeli novi izbor', 'Novi mali naslov', 'Mirisna njega',
            'Fotografija mirisne njege', 'Naši novi kompleti', 'Odaberi komplet', $url,
            'href="/shop?njega=mirisna"', 'href="/bundle-artikli?izbor=novi"', '#heart'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->editor($block)->assertSet('form.watchline.card_1_image', $url)
            ->assertSet('form.watchline.card_1_image_alt', 'Fotografija mirisne njege');
    }

    public function test_product_manual_order_limit_columns_and_automatic_category_filter_survive_save(): void
    {
        $category = $this->category('saved-detergents');
        $first = $this->product('saved-first');
        $second = $this->product('saved-second');
        $outside = $this->product('saved-outside');
        $first->categories()->attach($category);
        $second->categories()->attach($category);
        $block = $this->block('latest');

        $this->editor($block)->set('form.title', 'Urednički izbor')
            ->set('form.subtitle', 'Proizvodi prema našem redoslijedu')
            ->set('form.cta_label', 'Pogledaj izbor')->set('form.cta_url', '/shop?izbor=rucni')
            ->set('form.watchline.eyebrow', 'Odabrano danas')
            ->set('form.watchline.source', 'manual')
            ->set('form.selected_item_ids', [$second->id, $first->id])
            ->set('form.items_limit', 2)
            ->set('form.watchline.desktop_columns', 2)->set('form.watchline.tablet_columns', 2)
            ->set('form.watchline.mobile_columns', 1)
            ->call('save')->assertHasNoErrors();

        $html = $this->widgetHtml($block);
        $this->assertStringContainsString('Urednički izbor', $html);
        $this->assertStringContainsString('Proizvodi prema našem redoslijedu', $html);
        $this->assertStringContainsString('href="/shop?izbor=rucni"', $html);
        $this->assertStringContainsString('data-desktop-cols="2"', $html);
        $this->assertStringContainsString('data-mobile-cols="1"', $html);
        $this->assertLessThan(strpos($html, 'saved-first'), strpos($html, 'saved-second'));
        $this->assertStringNotContainsString('saved-outside', $html);

        $this->editor($block)->set('form.watchline.source', 'filtered')
            ->set('form.watchline.category_ids', [$category->id])
            ->set('form.watchline.include_children', false)
            ->set('form.watchline.product_sort', 'price_asc')
            ->set('form.items_limit', 1)->call('save')->assertHasNoErrors();
        $html = $this->widgetHtml($block);
        $this->assertStringContainsString('saved-first', $html);
        $this->assertStringNotContainsString('saved-second', $html);
        $this->assertStringNotContainsString($outside->code, $html);
        $this->editor($block)->assertSet('form.watchline.source', 'filtered')
            ->assertSet('form.watchline.category_ids', [$category->id]);
    }

    public function test_promotions_widget_only_shows_saved_filtered_discounted_products(): void
    {
        $first = $this->product('promotion-first');
        $second = $this->product('promotion-second');
        $this->product('full-price-excluded');
        foreach ([[$first, 10], [$second, 30]] as [$product, $discount]) {
            $action = CatalogAction::create([
                'code' => 'saved-discount-'.$product->id, 'scope' => CatalogAction::SCOPE_PRODUCT,
                'type' => CatalogAction::TYPE_PERCENTAGE, 'discount_value' => $discount,
                'target_type' => CatalogAction::TARGET_PRODUCT,
                'audience_type' => CatalogAction::AUDIENCE_ALL, 'is_active' => true,
            ]);
            $action->targets()->create(['target_type' => CatalogAction::TARGET_PRODUCT, 'target_id' => $product->id]);
        }
        $block = $this->block('promotions');
        $this->editor($block)->set('form.title', 'Posebno odabrane akcije')
            ->set('form.watchline.source', 'promotions')
            ->set('form.watchline.product_sort', 'largest_discount')->set('form.items_limit', 1)
            ->call('save')->assertHasNoErrors();

        $html = $this->widgetHtml($block);
        $this->assertStringContainsString('Posebno odabrane akcije', $html);
        $this->assertStringContainsString('promotion-second', $html);
        $this->assertStringNotContainsString('promotion-first', $html);
        $this->assertStringNotContainsString('full-price-excluded', $html);
    }

    public function test_brand_manual_order_and_all_source_change_after_save(): void
    {
        $first = $this->manufacturer('saved-brand-first');
        $second = $this->manufacturer('saved-brand-second');
        $third = $this->manufacturer('saved-brand-third');
        $block = $this->block('brands');
        $this->editor($block)->set('form.title', 'Naši odabrani brandovi')
            ->set('form.subtitle', 'Brandovi za naš dom')
            ->set('form.cta_label', 'Sve marke')->set('form.cta_url', '/brendovi?izbor=novi')
            ->set('form.watchline.eyebrow', 'Naš odabir')
            ->set('form.watchline.source', 'manual')
            ->set('form.watchline.visible_count', 1)
            ->set('form.selected_item_ids', [$second->id, $first->id])
            ->call('save')->assertHasNoErrors();

        $html = $this->widgetHtml($block);
        $this->assertSame([$second->id, $first->id], $this->ids($html, 'data-watchline-brand-id'));
        $this->assertStringContainsString('data-watchline-brand-columns="1"', $html);
        foreach (['Naši odabrani brandovi', 'Brandovi za naš dom', 'Naš odabir', 'Sve marke', 'href="/brendovi?izbor=novi"'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $this->editor($block)->set('form.watchline.source', 'all')
            ->set('form.watchline.visible_count', 3)->call('save')->assertHasNoErrors();
        $ids = $this->ids($this->widgetHtml($block), 'data-watchline-brand-id');
        $this->assertEqualsCanonicalizing([$first->id, $second->id, $third->id], $ids);
    }

    public function test_blog_manual_order_and_automatic_featured_source_and_limit_reach_frontend(): void
    {
        $older = $this->blogPost('saved-older-post', ['is_featured' => true, 'published_at' => now()->subDays(3)]);
        $newer = $this->blogPost('saved-newer-post', ['published_at' => now()->subDay()]);
        $future = $this->blogPost('saved-future-post', ['is_featured' => true, 'published_at' => now()->addDay()]);
        $block = $this->block('blog');
        $this->editor($block)->set('form.title', 'Naše vijesti')
            ->set('form.subtitle', 'Savjeti iz naše praonice')
            ->set('form.cta_label', 'Sve nove objave')->set('form.cta_url', '/blog?izbor=novi')
            ->set('form.watchline.eyebrow', 'Čitajte danas')
            ->set('form.blog_source', 'latest')->set('form.items_limit', 2)
            ->set('form.selected_item_ids', [$older->id, $future->id, $newer->id])
            ->call('save')->assertHasNoErrors();

        $html = $this->widgetHtml($block);
        $this->assertSame([$older->id, $newer->id], $this->ids($html, 'data-watchline-blog-id'));
        foreach (['Naše vijesti', 'Savjeti iz naše praonice', 'Čitajte danas', 'Sve nove objave', 'href="/blog?izbor=novi"'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->editor($block)->set('form.selected_item_ids', [])
            ->set('form.blog_source', 'featured')->set('form.items_limit', 1)
            ->call('save')->assertHasNoErrors();
        $this->assertSame([$older->id], $this->ids($this->widgetHtml($block), 'data-watchline-blog-id'));
        $this->editor($block)->set('form.blog_source', 'latest')->call('save')->assertHasNoErrors();
        $this->assertSame([$newer->id], $this->ids($this->widgetHtml($block), 'data-watchline-blog-id'));
    }

    public function test_review_empty_copy_link_featured_filter_and_limit_reach_frontend_after_save(): void
    {
        $block = $this->block('reviews');
        $this->editor($block)->set('form.title', 'Iskustva naših kupaca')
            ->set('form.subtitle', 'Podijelite svoje iskustvo')
            ->set('form.cta_label', 'Pročitajte iskustva')->set('form.cta_url', '/recenzije?izbor=novi')
            ->set('form.watchline.eyebrow', 'Kupci o nama')
            ->set('form.watchline.empty_message', 'Čekamo vaše prve dojmove.')
            ->set('form.reviews_featured_only', true)->set('form.items_limit', 1)
            ->call('save')->assertHasNoErrors();
        $html = $this->widgetHtml($block);
        foreach (['Iskustva naših kupaca', 'Podijelite svoje iskustvo', 'Kupci o nama',
            'Čekamo vaše prve dojmove.', 'Pročitajte iskustva', 'href="/recenzije?izbor=novi"'] as $text) {
            $this->assertStringContainsString($text, $html);
        }

        $product = $this->product('reviewed-product');
        $this->review($product, 'Starija izdvojena recenzija', true);
        $this->review($product, 'Novija izdvojena recenzija', true);
        $this->review($product, 'Neizdvojena recenzija', false);
        $html = $this->widgetHtml($block);
        $this->assertStringContainsString('Novija izdvojena recenzija', $html);
        $this->assertStringNotContainsString('Starija izdvojena recenzija', $html);
        $this->assertStringNotContainsString('Neizdvojena recenzija', $html);
        $this->assertStringNotContainsString('Čekamo vaše prve dojmove.', $html);
        $this->editor($block)->set('form.reviews_featured_only', false)
            ->call('save')->assertHasNoErrors();
        $this->assertStringContainsString('Neizdvojena recenzija', $this->widgetHtml($block));
    }

    public function test_clearing_a_link_does_not_restore_default_ctas_on_any_homepage_widget(): void
    {
        $this->manufacturer('clear-link-brand');
        $this->blogPost('clear-link-post', ['published_at' => now()->subDay()]);

        foreach (['campaigns', 'categories', 'brands', 'blog', 'reviews'] as $suffix) {
            $block = $this->block($suffix);
            $this->editor($block)->set('form.cta_label', 'Privremeni poziv '.$suffix)
                ->set('form.cta_url', '')->call('save')->assertHasNoErrors();
            $translation = $block->fresh()->translations()->sole();
            $this->assertSame('', WatchlineWidgetSettings::forBlock($block, $translation)['cta_url']);
            $this->editor($block)->assertSet('form.cta_url', '');
            $this->assertStringNotContainsString('Privremeni poziv '.$suffix, $this->widgetHtml($block));
        }
    }

    private function block(string $suffix): ContentBlock
    {
        return ContentBlock::where('code', 'lavaggio-home-'.$suffix)->sole();
    }

    private function editor(ContentBlock $block)
    {
        return Livewire::actingAs($this->admin)->test(Form::class, ['blockId' => $block->id]);
    }

    private function widgetHtml(ContentBlock $block): string
    {
        $html = $this->get('/')->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $nodes = (new \DOMXPath($document))->query('//*[@data-lavaggio-widget="'.$block->code.'"]');
        $this->assertSame(1, $nodes->length);

        return $document->saveHTML($nodes->item(0));
    }

    private function ids(string $html, string $attribute): array
    {
        preg_match_all('/'.preg_quote($attribute, '/').'="([0-9]+)"/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    private function category(string $code): Category
    {
        $category = Category::create(['code' => $code, 'scope' => 'catalog', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);

        return $category;
    }

    private function product(string $code): Product
    {
        $product = Product::create([
            'code' => $code, 'sku' => $code, 'is_active' => true, 'stock_qty' => 3,
            'base_price' => 100 + Product::count(),
            'payload' => ['opencart' => ['image' => 'catalog/'.$code.'.jpg']],
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);

        return $product;
    }

    private function manufacturer(string $code): Manufacturer
    {
        $manufacturer = Manufacturer::create(['code' => $code, 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        config(['manufacturer_logos.'.$code => '/assets/lavaggio/brands/ariel.webp']);
        $this->product('product-'.$code)->update(['manufacturer_id' => $manufacturer->id]);

        return $manufacturer;
    }

    private function blogPost(string $code, array $attributes): BlogPost
    {
        $post = BlogPost::create(array_replace(['code' => $code, 'is_active' => true], $attributes));
        $post->translations()->create(['locale' => 'hr', 'title' => $code, 'slug' => $code]);

        return $post;
    }

    private function review(Product $product, string $body, bool $featured): void
    {
        Comment::create([
            'commentable_type' => Product::class, 'commentable_id' => $product->id,
            'locale' => 'hr', 'author_name' => 'Kupac', 'body' => $body, 'rating' => 4,
            'status' => Comment::STATUS_APPROVED, 'is_featured' => $featured,
        ]);
    }
}
