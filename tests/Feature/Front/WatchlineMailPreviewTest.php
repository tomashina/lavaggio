<?php

namespace Tests\Feature\Front;

use App\Services\Preview\WatchlineMailPreviewService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WatchlineMailPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'storefront_cache.enabled' => false]);
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
    }

    public function test_gallery_offers_the_real_mail_variants_with_sandboxed_mobile_and_desktop_preview(): void
    {
        $response = $this->get('/preview/watchline-mail?sample=gift-delivered')
            ->assertOk()->assertSee('Samo ogledni podaci. Poruke se ne šalju.')
            ->assertSee('data-mail-width="mobile"', false)->assertSee('data-mail-width="desktop"', false)
            ->assertSee('sandbox=""', false)->assertSee('Neaktivni modul')
            ->assertSee(route('preview.watchline-mail.show', 'gift-delivered'), false);

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString("form-action 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertCount(18, app(WatchlineMailPreviewService::class)->samples());
        $this->get('/preview/watchline-mail?sample=missing')->assertNotFound();
        $this->get('/preview/watchline-mail/missing')->assertNotFound();
    }

    public function test_every_sample_renders_synthetic_data_without_writing_records_sending_messages_or_http(): void
    {
        $writes = [];
        $customerReads = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes, &$customerReads): void {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
            if (preg_match('/\bfrom\s+["`\[]?(users|orders|gift_vouchers|contract_withdrawals|contact_messages|customer_offers)["`\]]?\b/i', $query->sql)) {
                $customerReads[] = $query->sql;
            }
        });

        foreach (app(WatchlineMailPreviewService::class)->samples() as $key => $sample) {
            $html = $this->get('/preview/watchline-mail/'.$key)->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->getContent();
            $this->assertStringContainsString('<html', $html, $key);
            $this->assertStringNotContainsString('signature=', $html, $key);
            $this->assertStringNotContainsString('preview-only-not-valid', $html, $key);
        }

        $this->assertSame([], $writes);
        $this->assertSame([], $customerReads);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_previews_are_unavailable_in_production_and_other_storefronts_even_with_registered_routes(): void
    {
        $this->app->instance('env', 'production');
        $this->get('/preview/watchline-mail')->assertNotFound();
        $this->get('/preview/watchline-mail/order-customer')->assertNotFound();

        $this->app->instance('env', 'testing');
        config(['storefront.home' => 'libermedia']);
        $this->get('/preview/watchline-mail')->assertNotFound();
        $this->get('/preview/watchline-mail/order-customer')->assertNotFound();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(WatchlineMailPreviewService::class)->render('order-customer');
    }

    public function test_unpaid_bank_previews_embed_the_fixed_non_payment_pdf417_fixture_only(): void
    {
        $fixture = file_get_contents(public_path('assets/previews/watchline-payment-demo.png'));
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $fixture);
        $this->assertStringContainsString('OGLEDNI PRIKAZ - NIJE ZA UPLATU', $fixture);
        $this->assertStringNotContainsString('HRVHUB30', $fixture);
        $this->assertStringNotContainsString('HR00 0000', $fixture);

        foreach (['order-bank', 'gift-purchase'] as $key) {
            $html = $this->get('/preview/watchline-mail/'.$key)->assertOk()->getContent();
            $this->assertStringContainsString('data:image/png;base64,'.base64_encode($fixture), $html, $key);
            $this->assertStringContainsString('NIJE ZA UPLATU', $html, $key);
        }
        foreach (['order-customer', 'gift-paid'] as $key) {
            $html = $this->get('/preview/watchline-mail/'.$key)->assertOk()->getContent();
            $this->assertStringNotContainsString(base64_encode($fixture), $html, $key);
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
