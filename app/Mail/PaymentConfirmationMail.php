<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $tripName,
        public string $companyName,
        public float $amount,
        public string $currency,
    ) {
        //
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Payment received for {$this->tripName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-confirmation',
            with: [
                'recipientName' => $this->recipientName,
                'tripName' => $this->tripName,
                'companyName' => $this->companyName,
                'amount' => $this->amount,
                'currency' => $this->currency,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
