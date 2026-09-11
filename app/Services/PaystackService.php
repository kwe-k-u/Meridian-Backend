<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for Paystack's hosted-checkout payment API (https://paystack.com/docs/api/).
 *
 * Only the hosted-checkout ("transaction") flow is used: initializeTransaction() creates a
 * page the customer pays through, and verifyTransaction() lets us ask Paystack directly
 * (server-to-server, with our own secret key) what a payment's real status is. Paystack
 * webhooks are signed (see PaystackPaymentController::webhook), but the webhook handler still
 * re-verifies via this class rather than trusting the payload's own status.
 */
class PaystackService
{
    private string $baseUrl;
    private ?string $secretKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.paystack.base_url'), '/');
        $this->secretKey = config('services.paystack.secret_key');
    }

    /**
     * Initializes a hosted-checkout transaction (POST /transaction/initialize). Amount is
     * given in the currency's major unit (e.g. cedis) and converted to the minor unit
     * Paystack expects (pesewas) here. Returns Paystack's decoded JSON body — callers should
     * check `status === true` and read `data.authorization_url` / `data.reference`.
     */
    public function initializeTransaction(string $reference, float $amount, string $currency, string $email, string $callbackUrl): array
    {
        $response = Http::withToken($this->secretKey)
            ->post("{$this->baseUrl}/transaction/initialize", [
                'reference' => $reference,
                'amount' => (int) round($amount * 100),
                'currency' => $currency,
                'email' => $email,
                'callback_url' => $callbackUrl,
            ]);

        return $response->json() ?? [];
    }

    /**
     * Server-to-server payment status check by our own reference (GET
     * /transaction/verify/{reference}).
     */
    public function verifyTransaction(string $reference): array
    {
        $response = Http::withToken($this->secretKey)
            ->get("{$this->baseUrl}/transaction/verify/" . rawurlencode($reference));

        return $response->json() ?? [];
    }
}
