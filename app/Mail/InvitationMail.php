<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $companyName,
        public string $inviterName,
        public string $role,
        public string $acceptUrl,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You've been invited to join {$this->companyName} on Meridian",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
            with: [
                'companyName' => $this->companyName,
                'inviterName' => $this->inviterName,
                'role' => $this->role,
                'acceptUrl' => $this->acceptUrl,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
