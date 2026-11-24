<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Product\Form as ProductForm;
use App\Livewire\Admin\Content\Blog\Form as BlogForm;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class StorefrontDraftPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'commerce.b2c_only' => true]);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('catalog_use_blog', true);
    }

    public function test_product_draft_can_be_saved_and_previewed_from_the_list_and_editor(): void
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);
        $form = Livewire::actingAs($user)->test(ProductForm::class)
            ->assertSee('Spremi nacrt i pregledaj')
            ->set('form.code', 'DRAFT-01')->set('form.name', 'Nacrt deterdženta')
            ->call('generateSlug')->set('form.description', '<p>Uputa za doziranje iz nacrta.</p>')
            ->set('form.base_price', 12)->set('form.stock_qty', 3)
            ->call('save', true)->assertHasNoErrors();
        $product = Product::where('code', 'DRAFT-01')->sole();
        $translation = $product->translations()->sole();
        $preview = route('admin.products.preview', $translation);
        $public = route('products.show', $translation->slug);
        $form->assertRedirect($preview);
        $this->assertFalse($product->is_active);

        $this->get(route('admin.products'))->assertOk()->assertSeeHtml('href="'.$preview.'"');
        $this->get(route('admin.products.edit', $product))->assertOk()->assertSeeHtml('href="'.$preview.'"');
        app(SystemSettingsService::class)->putMany([
            'store_analytics_enabled' => true, 'store_analytics_ga4_measurement_id' => 'G-DRAFTTEST',
        ]);
        $this->app['env'] = 'production';
        config(['commerce.local_safe_mode' => false]);
        $response = $this->get($preview)->assertOk()->assertViewIs('front.desktop.products.show')
            ->assertSee('Nacrt deterdženta')->assertSee('Uputa za doziranje iz nacrta.')
            ->assertSee('Pregled spremljene verzije')->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('G-DRAFTTEST')->assertDontSee('shop-analytics.js')
            ->assertSessionMissing('front_recently_viewed_products');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->get($public)->assertNotFound();
        $this->get(route('shop.index'))->assertOk()->assertDontSee('Nacrt deterdženta');

        $product->update(['is_active' => true]);
        $this->get($public)->assertOk()->assertSee('Uputa za doziranje iz nacrta.')
            ->assertSee('G-DRAFTTEST')->assertDontSee('Pregled spremljene verzije');
    }

    public function test_blog_preview_shows_draft_and_scheduled_content_without_changing_the_publication_time(): void
    {
        $user = User::factory()->create();
        Bouncer::assign('admin')->to($user);
        $form = Livewire::actingAs($user)->test(BlogForm::class)
            ->assertSee('Spremi nacrt i pregledaj')
            ->set('form.title', 'Savjeti iz nacrta')->set('form.body_html', '<p>Tekst prije objave.</p>')
            ->call('save', true)->assertHasNoErrors();
        $post = BlogPost::where('code', 'savjeti-iz-nacrta')->sole();
        $translation = $post->translations()->sole();
        $preview = route('admin.content.blog.preview', $translation);
        $public = route('blog.show', $translation->slug);
        $form->assertRedirect($preview);
        $this->assertFalse($post->is_active);

        $this->get(route('admin.content.blog.edit', $post))->assertOk()->assertSeeHtml('href="'.$preview.'"');
        $this->get($preview)->assertOk()->assertViewIs('front.desktop.blog.show')->assertSee('Tekst prije objave.');
        $this->get($public)->assertNotFound();

        $scheduled = now()->addHour()->startOfSecond();
        $post->update(['is_active' => true, 'published_at' => $scheduled]);
        $this->get(route('admin.content.blog.index'))->assertOk()->assertSeeHtml('href="'.$preview.'"');
        $response = $this->get($preview)->assertOk()->assertSee('Tekst prije objave.')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($post->fresh()->published_at->equalTo($scheduled));
        $this->get($public)->assertNotFound();
        $this->get(route('blog.index'))->assertOk()->assertDontSee('Savjeti iz nacrta');
        $this->travelTo($scheduled);
        $this->get($public)->assertOk()->assertSee('Tekst prije objave.')->assertDontSee('Pregled spremljene verzije');
    }

    public function test_preview_links_require_module_access_and_open_the_selected_translation(): void
    {
        $product = Product::create(['code' => 'DUAL', 'is_active' => false]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Hrvatski nacrt', 'slug' => 'isti-slug']);
        $english = $product->translations()->create(['locale' => 'en', 'name' => 'English draft', 'slug' => 'isti-slug']);
        $post = BlogPost::create(['code' => 'BLOG', 'is_active' => false]);
        $article = $post->translations()->create(['locale' => 'hr', 'title' => 'Privatni članak', 'slug' => 'privatni-clanak']);
        $productUrl = route('admin.products.preview', $english);
        $postUrl = route('admin.content.blog.preview', $article);

        $this->get($productUrl)->assertRedirect(route('login'));
        $this->get($postUrl)->assertRedirect(route('login'));
        $customer = User::factory()->create();
        $this->actingAs($customer)->get($productUrl)->assertForbidden();
        $this->get($postUrl)->assertForbidden();
        $this->assertFalse($customer->isA('superadmin'));

        $catalogViewer = User::factory()->create();
        Bouncer::allow($catalogViewer)->to(['admin.access', 'catalog.products.view']);
        $this->actingAs($catalogViewer)->withSession(['front_locale' => 'hr'])->get($productUrl)
            ->assertOk()->assertSee('English draft')->assertDontSee('Hrvatski nacrt')->assertSessionHas('front_locale', 'hr');
        $this->get($postUrl)->assertForbidden();
        $blogEditor = User::factory()->create();
        Bouncer::allow($blogEditor)->to(['admin.access', 'content.blog.update']);
        $this->actingAs($blogEditor)->get($postUrl)->assertOk()->assertSee('Privatni članak');
        $this->get($productUrl)->assertForbidden();
        $this->app['auth']->guard()->logout();
        $this->get($postUrl)->assertRedirect(route('login'));
        $this->get(route('products.show', ['slug' => 'isti-slug', 'preview' => 1]))->assertNotFound();
        $this->get(route('blog.show', ['slug' => 'privatni-clanak', 'preview' => 1]))->assertNotFound();
    }
}
