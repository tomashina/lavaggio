<?php

namespace App\Mail;

use App\Models\Sales\GiftVoucher;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GiftVoucherDelivered extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public GiftVoucher $voucher) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('storefront.home') === 'watchline'
            ? 'Poklon za vas: Watchline poklon bon'
            : 'Poklon za vas: Liber Media poklon bon');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.gift-vouchers.delivered');
    }
}
