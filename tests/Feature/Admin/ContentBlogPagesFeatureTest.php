<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Content\Blog\Form as BlogForm;
use App\Livewire\Admin\Content\Page\Form as PageForm;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\Page\InfoPage;
use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ContentBlogPagesFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_routes_are_blocked_when_feature_disabled(): void
    {
        $user = $this->makeAdminUser();

        $response = $this->actingAs($user)->get('/admin/content/blog');

        $response
            ->assertRedirect(route('admin.settings.system.catalog-features'))
            ->assertSessionHas('notify.type', 'warning');
    }

    public function test_admin_can_create_blog_post(): void
    {
        app(SystemSettingsService::class)->put('catalog_use_blog', true);

        $user = $this->makeAdminUser();

        Livewire::actingAs($user)
            ->test(BlogForm::class)
            ->set('form.code', 'blog-post-1')
            ->set('form.is_active', true)
            ->set('form.is_featured', true)
            ->set('form.locale', 'en')
            ->set('form.title', 'First Blog Post')
            ->set('form.excerpt', 'Kratki sažetak članka.')
            ->set('form.slug', 'first-blog-post')
            ->call('save')
            ->assertRedirect(route('admin.content.blog.index', ['locale' => 'en']));

        $post = BlogPost::query()->where('code', 'blog-post-1')->first();

        $this->assertNotNull($post);
        $this->assertTrue((bool) $post->is_featured);
        $this->assertSame('First Blog Post', (string) $post->translation('en')->first()?->title);
        $this->assertSame('Kratki sažetak članka.', $post->translation('en')->first()?->excerpt);
    }

    public function test_admin_can_create_info_page(): void
    {
        $user = $this->makeAdminUser();

        Livewire::actingAs($user)
            ->test(PageForm::class)
            ->set('form.code', 'shipping-info')
            ->set('form.layout', 'default')
            ->set('form.is_active', true)
            ->set('form.show_in_footer', true)
            ->set('form.locale', 'en')
            ->set('form.title', 'Shipping Info')
            ->set('form.slug', 'shipping-info')
            ->call('save')
            ->assertRedirect(route('admin.content.pages.index', ['locale' => 'en']));

        $page = InfoPage::query()->where('code', 'shipping-info')->first();

        $this->assertNotNull($page);
        $this->assertTrue((bool) $page->show_in_footer);
        $this->assertSame('Shipping Info', (string) $page->translation('en')->first()?->title);
    }

    public function test_lavaggio_video_cover_and_article_player_follow_admin_creation_and_video_changes(): void
    {
        config(['storefront.home' => 'lavaggio', 'storefront_cache.enabled' => false, 'app.locale' => 'hr', 'app.fallback_locale' => 'hr']);
        app()->setLocale('hr');
        app(SystemSettingsService::class)->put('catalog_use_blog', true);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        $user = $this->makeAdminUser();

        Livewire::actingAs($user)->test(BlogForm::class)
            ->set('form.locale', 'hr')->set('form.title', 'Video article')
            ->set('form.body_html', '<iframe class="ql-video" src="https://www.youtube.com/embed/u1jjjxJxWic" width="315" height="560"></iframe><p>Article text.</p>')
            ->call('setTab', 'media')->assertSee('https://i.ytimg.com/vi/u1jjjxJxWic/oar2.jpg', false)
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $post = BlogPost::where('code', 'video-article')->firstOrFail();
        $this->get('/blog')->assertOk()->assertSee('https://i.ytimg.com/vi/u1jjjxJxWic/oar2.jpg', false);
        $this->get('/blog/video-article')->assertOk()->assertSee('data-lavaggio-video-open', false)
            ->assertSee('data-video-id="u1jjjxJxWic"', false)->assertSee('Article text.')
            ->assertDontSee('<iframe', false);

        Livewire::actingAs($user)->test(BlogForm::class, ['postId' => $post->id])
            ->set('form.body_html', '<iframe src="https://www.youtube-nocookie.com/embed/yOjuuBBVdmc" width="640" height="360"></iframe><p>Updated text.</p>')
            ->call('save')->assertHasNoErrors()->assertRedirect();

        $this->get('/blog')->assertOk()->assertSee('https://i.ytimg.com/vi/yOjuuBBVdmc/oar2.jpg', false)
            ->assertDontSee('u1jjjxJxWic');
        $this->get('/blog/video-article')->assertOk()->assertSee('data-video-id="yOjuuBBVdmc"', false)
            ->assertSee('https://i.ytimg.com/vi/yOjuuBBVdmc/hqdefault.jpg', false)
            ->assertSee('Updated text.')->assertDontSee('<iframe', false)->assertDontSee('u1jjjxJxWic');
        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    private function makeAdminUser(): User
    {
        $user = User::factory()->create();

        Bouncer::role()->firstOrCreate(['name' => 'admin']);
        Bouncer::assign('admin')->to($user);

        return $user;
    }
}
