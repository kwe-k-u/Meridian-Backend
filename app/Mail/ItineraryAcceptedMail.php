<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Notifies agency staff that a traveler has accepted an itinerary option.
class ItineraryAcceptedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $itineraryName,
        public string $tripName,
        public string $customerName,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Itinerary accepted: {$this->tripName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.itinerary-accepted-staff',
            with: [
                'recipientName' => $this->recipientName,
                'itineraryName' => $this->itineraryName,
                'tripName' => $this->tripName,
                'customerName' => $this->customerName,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
