<?php

namespace App\Mail;

use App\Models\Catalog\StockNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BackInStockMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StockNotification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Ponovno dostupno: '.$this->notification->displayName());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.stock-notifications.available', with: [
            'targetName' => $this->notification->displayName(),
            'targetUrl' => $this->notification->storefrontUrl(),
            'unsubscribeUrl' => route('stock-notifications.unsubscribe', ['token' => $this->notification->token]),
            'isBundle' => (bool) $this->notification->bundle_id,
        ]);
    }
}
