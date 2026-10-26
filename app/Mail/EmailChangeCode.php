<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailChangeCode extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $firstName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Threefold email change code');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.email-change-code');
    }
}