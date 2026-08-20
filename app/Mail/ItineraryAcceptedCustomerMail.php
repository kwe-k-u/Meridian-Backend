<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// Confirms to the traveler that their itinerary selection was recorded.
class ItineraryAcceptedCustomerMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $itineraryName,
        public string $tripName,
        public string $companyName,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your itinerary for {$this->tripName} is confirmed",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.itinerary-accepted-customer',
            with: [
                'recipientName' => $this->recipientName,
                'itineraryName' => $this->itineraryName,
                'tripName' => $this->tripName,
                'companyName' => $this->companyName,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
