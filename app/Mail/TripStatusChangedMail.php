<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TripStatusChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $tripName,
        public string $companyName,
        public string $status,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Update on your trip: {$this->tripName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.trip-status-changed',
            with: [
                'recipientName' => $this->recipientName,
                'tripName' => $this->tripName,
                'companyName' => $this->companyName,
                'status' => $this->status,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
