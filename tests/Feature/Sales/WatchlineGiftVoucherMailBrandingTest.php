<?php

namespace Tests\Feature\Sales;

use App\Mail\GiftVoucherDelivered;
use App\Models\Sales\GiftVoucher;
use App\Models\Sales\Order\Order;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WatchlineGiftVoucherMailBrandingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['storefront.home' => 'watchline', 'app.name' => 'WATCH LINE AM - Web Shop']);
        Mail::fake();
    }

    public function test_recipient_mail_uses_watchline_logo_copy_and_subject_while_preserving_voucher_data_and_escaping(): void
    {
        $voucher = $this->voucher();
        $mail = new GiftVoucherDelivered($voucher);
        $html = $mail->render();

        $this->assertSame('Poklon za vas: Watchline poklon bon', $mail->envelope()->subject);
        $this->assertStringContainsString(asset('assets/brand/watchline-mail-logo.png'), $html);
        $this->assertStringContainsString('alt="Watchline"', $html);
        $this->assertStringContainsString('background:#edf6fc', $html);
        $this->assertStringContainsString('Poklon bon Watchline', $html);
        $this->assertStringContainsString('50,00 €', $html);
        $this->assertStringContainsString('WATCHLINE-FIXTURE-GIFT', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('Liber', $html);
        $this->assertStringNotContainsString('igru i učenje', $html);
        Mail::assertNothingSent();
    }

    public function test_buyer_confirmation_has_watchline_logo_and_no_toy_copy_or_recipient_voucher_code(): void
    {
        $html = $this->purchaseHtml();

        $this->assertStringContainsString(asset('assets/brand/watchline-mail-logo.png'), $html);
        $this->assertStringContainsString('Hvala što darujete poseban trenutak.', $html);
        $this->assertStringContainsString('WATCHLINE-MAIL-FIXTURE', $html);
        $this->assertStringContainsString('50,00 €', $html);
        $this->assertStringNotContainsString('Liber', $html);
        $this->assertStringNotContainsString('igru i učenje', $html);
        $this->assertStringNotContainsString('WATCHLINE-FIXTURE-GIFT', $html);
        Mail::assertNothingSent();
    }

    public function test_other_storefront_keeps_existing_gift_mail_branding(): void
    {
        config(['storefront.home' => 'auto']);
        $mail = new GiftVoucherDelivered($this->voucher());
        $recipientHtml = $mail->render();
        $buyerHtml = $this->purchaseHtml();

        $this->assertSame('Poklon za vas: Liber Media poklon bon', $mail->envelope()->subject);
        $this->assertStringContainsString('Poklon bon Liber Media', $recipientHtml);
        $this->assertStringContainsString('Odaberite nešto za igru i učenje.', $recipientHtml);
        $this->assertStringContainsString('Hvala što poklanjate igru i učenje.', $buyerHtml);
        $this->assertStringNotContainsString('watchline-logo-on-dark.svg', $recipientHtml.$buyerHtml);
        Mail::assertNothingSent();
    }

    private function purchaseHtml(): string
    {
        return view('emails.gift-vouchers.purchase', [
            'order' => new Order(['order_number' => 'WATCHLINE-MAIL-FIXTURE', 'grand_total' => 50, 'paid_at' => now()]),
            'voucher' => $this->voucher(),
            'bankTransfer' => null,
            'purchaseUrl' => 'https://watchline.test/gift-voucher/fixture',
        ])->render();
    }

    private function voucher(): GiftVoucher
    {
        $voucher = new GiftVoucher(['initial_amount' => 50, 'balance' => 50, 'recipient_name' => 'Primatelj', 'sender_name' => 'Pošiljatelj',
            'recipient_email' => 'recipient@example.test', 'message' => '<script>fixture</script> Sretan rođendan!', 'expires_at' => now()->addYear()]);
        $voucher->storeCode('WATCHLINE-FIXTURE-GIFT');

        return $voucher;
    }
}
