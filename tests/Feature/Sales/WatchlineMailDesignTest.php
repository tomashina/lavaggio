<?php

namespace Tests\Feature\Sales;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderItem;
use App\Models\User;
use App\Services\Front\StoreNotificationService;
use App\Services\Front\StoreSettingsService;
use App\Services\Payments\BankTransferUpiService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class WatchlineMailDesignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline']);
        app()->setLocale('hr');
        Mail::fake();
        Http::preventStrayRequests();
    }

    public function test_auth_messages_keep_the_actual_reset_and_signed_verification_urls_in_the_branded_body(): void
    {
        $user = new User(['name' => 'Ogled', 'email' => 'mail-fixture@example.invalid']);
        $user->id = 123;
        $reset = (new ResetPassword('fixture-token-not-stored'))->toMail($user);
        $verify = (new VerifyEmail)->toMail($user);

        foreach ([$reset, $verify] as $mail) {
            $this->assertSame($mail->actionUrl, $mail->viewData['actionUrl']);
            $html = (string) $mail->render();
            $this->assertStringContainsString(e($mail->actionUrl), $html);
            $this->assertStringContainsString('watchline-mail-logo.png', $html);
            $this->assertStringNotContainsString('Liber Media', $html);
        }
        $this->assertStringContainsString('fixture-token-not-stored', $reset->viewData['actionUrl']);
        $this->assertStringContainsString('signature=', $verify->viewData['actionUrl']);
        Mail::assertNothingSent();
    }

    public function test_order_data_keeps_amounts_and_uses_imported_images_when_no_native_media_exists(): void
    {
        config(['legacy_media.enabled' => true, 'legacy_media.remote_only' => false, 'legacy_media.origin' => 'https://www.watchline.hr', 'legacy_media.directories' => ['catalog']]);
        $this->mock(BankTransferUpiService::class)->shouldReceive('ensureForOrder')->once()->andReturn(null);
        $settings = $this->mock(StoreSettingsService::class);
        $settings->shouldReceive('all')->once()->andReturn(['branding' => ['store_name' => 'Watchline']]);
        $product = new Product(['sku' => 'MAIL-EXAMPLE', 'payload' => ['opencart' => ['image' => 'catalog/mail-fixture.jpg']]]);
        $product->setRelation('media', new Collection);
        $item = new OrderItem(['name' => 'Ogledni sat', 'sku' => 'MAIL-EXAMPLE', 'quantity' => 2, 'unit_price' => 113.9, 'line_total' => 227.8]);
        $item->setRelation('product', $product)->setRelation('productOptionValue', null);
        $order = new Order(['order_number' => 'MAIL-EXAMPLE-001', 'currency_code' => 'EUR', 'customer_name' => 'Ogled', 'grand_total' => 227.8, 'subtotal' => 227.8]);
        $order->setRelation('items', new Collection([$item]));

        $method = new \ReflectionMethod(StoreNotificationService::class, 'buildOrderMailData');
        $data = $method->invoke(new StoreNotificationService($settings), $order);

        $this->assertSame('https://www.watchline.hr/image/catalog/mail-fixture.jpg', $data['items'][0]['image_url']);
        $this->assertSame(2, $data['items'][0]['quantity']);
        $this->assertSame('113,90 €', $data['items'][0]['unit_price']);
        $this->assertSame('227,80 €', $data['items'][0]['line_total']);
        $this->assertSame('227,80 €', $data['totals']['grand_total']);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_unpaid_voucher_embeds_the_bank_barcode_as_an_inline_attachment_and_paid_voucher_does_not(): void
    {
        $binary = file_get_contents(public_path('assets/previews/watchline-payment-demo.png'));
        $email = new Email;
        $order = new Order(['order_number' => 'BARCODE-FIXTURE', 'grand_total' => 50]);
        $data = [
            'order' => $order, 'voucher' => null, 'purchaseUrl' => 'https://example.invalid/fixture',
            'bankTransfer' => ['qr_image_base64' => base64_encode($binary), 'qr_image_mime' => 'image/png'],
            'message' => new Message($email),
        ];
        $html = view('emails.gift-vouchers.purchase', $data)->render();
        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame($binary, $attachments[0]->getBody());
        $this->assertSame('inline', $attachments[0]->getDisposition());
        $this->assertStringContainsString('cid:'.$attachments[0]->getContentId(), $html);
        $this->assertStringContainsString('2D kod za uplatu', $html);
        $this->assertStringNotContainsString('OGLEDNI PRIKAZ', $html);

        $order->paid_at = now();
        $paidEmail = new Email;
        $data['message'] = new Message($paidEmail);
        $paidHtml = view('emails.gift-vouchers.purchase', $data)->render();
        $this->assertCount(0, $paidEmail->getAttachments());
        $this->assertStringNotContainsString('2D kod za uplatu', $paidHtml);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }
}
