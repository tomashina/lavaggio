<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WatchlineAutomationMail extends Mailable
{
    use Queueable;

    public function __construct(public array $messageData) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->messageData['subject']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.watchline.automation', with: $this->messageData);
    }
}
