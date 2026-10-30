<?php

namespace Tests\Feature\Sales;

use App\Mail\GiftVoucherDelivered;
use App\Mail\NewsletterCouponMail;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Services\Front\StoreNotificationService;
use App\Services\Front\StoreSettingsService;
use App\Services\Payments\BankTransferUpiService;
use App\Services\Preview\WatchlineMailPreviewService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class LavaggioMailDesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'lavaggio', 'storefront_cache.enabled' => false]);
        app()->setLocale('hr');
        Mail::fake();
        Http::preventStrayRequests();
        app(SystemSettingsService::class)->putMany([
            'store_footer_email_support' => 'podrska@example.invalid',
            'store_footer_phone' => '+385 00 123 456',
            'store_footer_address' => "Ogledna tvrtka\nOgledna ulica 1",
        ]);
    }

    public function test_every_outgoing_template_uses_lavaggio_identity_and_configured_footer(): void
    {
        $this->get('/preview/lavaggio-mail')->assertOk()
            ->assertSee('Pregled e-mail poruka | Lavaggio')->assertSee('/preview/lavaggio-mail/order-customer');

        foreach (app(WatchlineMailPreviewService::class)->samples() as $key => $sample) {
            $response = $this->get('/preview/lavaggio-mail/'.$key)->assertOk();
            $html = $response->getContent();
            $this->assertStringContainsString('lavaggio-mail-logo.png', $html, $key);
            $this->assertStringContainsString('alt="Lavaggio"', $html, $key);
            $this->assertStringContainsString('#848ac4', $html, $key);
            $this->assertStringContainsString('podrska@example.invalid', $html, $key);
            $this->assertStringContainsString('Ogledna tvrtka', $html, $key);
            foreach (['Watchline', 'Liber Media', 'BALI', 'igru i učenje', 'sunčane naočale'] as $oldCopy) {
                $this->assertStringNotContainsString($oldCopy, $html, $key);
            }
        }
        $this->get('/preview/lavaggio-mail/order-gls-locker')->assertSee('Ogledni GLS paketomat');
        $this->get('/preview/lavaggio-mail/order-bank')
            ->assertSee('Ogledni QR kod · nije za uplatu')->assertSee('NIJE ZA UPLATU')
            ->assertSee('Podaci su testni. Nemojte izvršavati uplatu prema ovoj poruci.')
            ->assertDontSee(__('mail.orders.bank_transfer_note'))
            ->assertSee('data:image/png;base64,'.base64_encode(file_get_contents(public_path('assets/previews/lavaggio-qr-demo.png'))), false);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_auth_links_and_subjects_keep_real_customer_actions_and_voucher_subject_is_branded(): void
    {
        $user = new User(['name' => 'Ogled', 'email' => 'kupac@example.invalid']);
        $user->id = 123;
        $reset = (new ResetPassword('fixture-reset-token'))->toMail($user);
        $verify = (new VerifyEmail)->toMail($user);
        foreach ([$reset, $verify] as $mail) {
            $this->assertStringContainsString('Lavaggio', $mail->subject);
            $this->assertSame($mail->actionUrl, $mail->viewData['actionUrl']);
            $this->assertStringContainsString(e($mail->actionUrl), (string) $mail->render());
        }
        $this->assertStringContainsString('fixture-reset-token', $reset->actionUrl);
        $this->assertStringContainsString('signature=', $verify->actionUrl);
        $this->assertSame('Poklon za vas: Lavaggio poklon bon', (new GiftVoucherDelivered(new GiftVoucher))->envelope()->subject);
        $this->assertSame('Lavaggio: vaših 15% popusta', (new NewsletterCouponMail('OSOBNI-KOD', 'Lavaggio', url('/'), discountPercent: 15))->envelope()->subject);
    }

    public function test_bank_payment_codes_are_inline_attachments_and_order_keeps_gls_locker_snapshot(): void
    {
        $binary = file_get_contents(public_path('assets/previews/watchline-payment-demo.png'));
        $email = new Email;
        $html = view('emails.watchline.bank-barcode', [
            'reference' => 'LAV-123', 'message' => new Message($email),
            'bankTransfer' => [
                'qr_image_base64' => base64_encode($binary), 'qr_image_mime' => 'image/png',
                'epc_qr_image_base64' => base64_encode($binary), 'epc_qr_image_mime' => 'image/png',
            ],
        ])->render();
        $this->assertStringContainsString('QR kod za uplatu (SEPA)', $html);
        $this->assertCount(2, $email->getAttachments());
        foreach ($email->getAttachments() as $attachment) {
            $this->assertSame('inline', $attachment->getDisposition());
            $this->assertSame($binary, $attachment->getBody());
            $this->assertStringContainsString('cid:'.$attachment->getContentId(), $html);
        }

        $this->mock(BankTransferUpiService::class)->shouldReceive('ensureForOrder')->once()->andReturn(null);
        $settings = $this->mock(StoreSettingsService::class);
        $settings->shouldReceive('all')->once()->andReturn(['branding' => ['store_name' => 'Lavaggio']]);
        $order = new Order([
            'order_number' => 'LAV-123', 'grand_total' => 25.50, 'currency_code' => 'EUR',
            'payload' => ['shipping' => ['gls_dpm' => [
                'id' => 'LOCKER-1', 'name' => 'GLS paketomat primjer', 'type' => 'parcel-locker',
                'address_line_1' => 'Ulica 1', 'postal_code' => '10000', 'city' => 'Zagreb',
            ]]],
        ]);
        $order->setRelation('items', new Collection);
        $method = new \ReflectionMethod(StoreNotificationService::class, 'buildOrderMailData');
        $data = $method->invoke(new StoreNotificationService($settings), $order);
        $this->assertSame('LOCKER-1', $data['gls_locker']['id']);
        $this->assertSame('Ulica 1', $data['gls_locker']['address_line_1']);
        $this->assertSame('25,50 €', $data['totals']['grand_total']);
    }

    public function test_lavaggio_previews_are_not_public_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->get('/preview/lavaggio-mail')->assertNotFound();
        $this->get('/preview/lavaggio-mail/order-customer')->assertNotFound();
    }

    public function test_voucher_receipt_displays_the_square_qr_and_keeps_test_payment_warning(): void
    {
        config(['gift_vouchers.enabled' => true, 'gift_vouchers.emails_enabled' => false]);
        $order = Order::create([
            'order_number' => 'LAV-VOUCHER-QR', 'grand_total' => 50, 'currency_code' => 'EUR',
            'customer_name' => 'Ogled', 'customer_email' => 'kupac@example.invalid',
            'payment_method_code' => 'bank', 'payload' => ['gift_voucher_purchase' => true],
        ]);
        GiftVoucher::create([
            'purchase_order_id' => $order->id, 'recipient_email' => 'primatelj@example.invalid',
            'initial_amount' => 50, 'balance' => 50,
        ]);
        $binary = file_get_contents(public_path('assets/previews/lavaggio-qr-demo.png'));
        $this->mock(BankTransferUpiService::class)->shouldReceive('ensureForOrder')->once()->andReturn([
            'receiver_iban' => 'XXXX', 'receiver_name' => 'Ogled', 'amount' => 50, 'model' => '00', 'reference' => '123',
            'instructions' => 'OGLEDNI PRIKAZ · NIJE ZA UPLATU', 'preview_only' => true,
            'epc_qr_image_base64' => base64_encode($binary), 'epc_qr_image_mime' => 'image/png',
        ]);
        $signed = URL::temporarySignedRoute('gift-vouchers.purchase', now()->addDays(30), ['orderNumber' => $order->order_number]);
        $this->get($signed)->assertOk()->assertSee('Ogledni QR kod · nije za uplatu')->assertSee('NIJE ZA UPLATU')
            ->assertSee('data:image/png;base64,'.base64_encode($binary), false)
            ->assertDontSee('Skenirajte kod u mobilnom bankarstvu koje podržava SEPA QR kod.');
    }
}
