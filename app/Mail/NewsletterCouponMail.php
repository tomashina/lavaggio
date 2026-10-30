<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterCouponMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $couponCode,
        public readonly string $storeName,
        public readonly string $shopUrl,
        public readonly string $logoUrl = '',
        public readonly float $discountPercent = 10,
        public readonly bool $watchline = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: \App\Support\Mail\StoreMailBrand::isLavaggio()
                ? 'Lavaggio: vaših '.$this->discountPercent.'% popusta'
                : __($this->watchline ? 'mail.watchline_newsletter_coupon.subject' : 'mail.newsletter_coupon.subject', ['code' => $this->couponCode, 'percent' => $this->discountPercent]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter.coupon',
            with: [
                'coupon_code' => $this->couponCode,
                'store_name' => $this->storeName,
                'shop_url' => $this->shopUrl,
                'logo_url' => $this->logoUrl,
                'watchline' => $this->watchline,
                'discount_percent' => $this->discountPercent,
            ],
        );
    }
}
