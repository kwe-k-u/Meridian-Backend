<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DemoInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $inviterName,
        public string $joinUrl,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'re invited to try the Meridian demo',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.demo-invitation',
            with: [
                'inviterName' => $this->inviterName,
                'joinUrl' => $this->joinUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
