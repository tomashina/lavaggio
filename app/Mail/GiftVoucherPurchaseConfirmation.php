<?php

namespace App\Mail;

use App\Models\Sales\GiftVoucher;
use App\Models\Sales\Order\Order;
use App\Services\Payments\BankTransferUpiService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class GiftVoucherPurchaseConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Vaš poklon bon – narudžba '.$this->order->order_number);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.gift-vouchers.purchase', with: [
            'voucher' => GiftVoucher::where('purchase_order_id', $this->order->id)->first(),
            'purchaseUrl' => URL::temporarySignedRoute('gift-vouchers.purchase', now()->addDays(30), ['orderNumber' => $this->order->order_number]),
            'bankTransfer' => app(BankTransferUpiService::class)->ensureForOrder($this->order),
        ]);
    }
}
