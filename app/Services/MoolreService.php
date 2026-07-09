<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for Moolre's mobile-money payment API (https://docs.moolre.com/).
 *
 * Only the hosted-checkout ("payment link") flow is used: generatePaymentLink() creates a
 * page the customer pays through, and checkPaymentStatus() lets us ask Moolre directly
 * (server-to-server, with our own keys) what a payment's real status is. The webhook
 * endpoint (MoolrePaymentController::webhook) always calls checkPaymentStatus() rather than
 * trusting its own inbound payload, since Moolre's docs don't document any signature/HMAC
 * verification for that callback.
 */
class MoolreService
{
    private string $baseUrl;
    private ?string $apiUser;
    private ?string $apiKey;
    private ?string $apiPubkey;
    private ?string $accountNumber;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.moolre.base_url'), '/');
        $this->apiUser = config('services.moolre.api_user');
        $this->apiKey = config('services.moolre.api_key');
        $this->apiPubkey = config('services.moolre.api_pubkey');
        $this->accountNumber = config('services.moolre.account_number');
    }

    /**
     * Generates a hosted checkout link for a one-off payment (POST /embed/link).
     * Returns Moolre's decoded JSON body — callers should check `status == 1` and read
     * `data.authorization_url` for success.
     */
    public function generatePaymentLink(string $externalRef, float $amount, string $currency, string $email, ?string $callbackUrl, ?string $redirectUrl): array
    {
        $response = Http::withHeaders($this->headers(['X-API-KEY' => $this->apiKey]))
            ->post("{$this->baseUrl}/embed/link", [
                'type' => 1,
                'amount' => (string) $amount,
                'currency' => $currency,
                'email' => $email,
                'externalref' => $externalRef,
                'accountnumber' => $this->accountNumber,
                'callback' => $callbackUrl ?? config('services.moolre.callback_url'),
                'redirect' => $redirectUrl,
                'reusable' => '0',
                'expiration_time' => 60,
            ]);

        return $response->json() ?? [];
    }

    /**
     * Server-to-server payment status check by our own external reference (POST
     * /open/transact/status, idtype=1 = "look up by externalref").
     */
    public function fetchTransactions(string $startDate=null, string $endDate=null): array
    {
        $response = Http::withHeaders($this->headers(['X-API-PUBKEY' => $this->apiPubkey]))
            ->post("{$this->baseUrl}/open/transact/status", [
                'type' => 1,
                'startdate' => $startDate,
                'enddate' => $endDate,
                'accountnumber' => $this->accountNumber,
            ]);

        return $response->json() ?? [];
    }

    /**
     * Server-to-server payment status check by our own external reference (POST
     * /open/transact/status, idtype=1 = "look up by externalref").
     */
    public function checkPaymentStatus(string $paidDateTime): array
    {
        $response = Http::withHeaders($this->headers(['X-API-PUBKEY' => $this->apiPubkey]))
            ->post("{$this->baseUrl}/open/transact/status", [
                'type' => 1,
                'limit' => 1,
                'startdate' => $paidDateTime,
                'enddate' => $paidDateTime,
                'accountnumber' => $this->accountNumber,
            ]);

        return $response->json() ?? [];
    }

    public function createCompanyWallet() {
        $response = Http::withHeaders($this->headers(['X-API-KEY' => $this->apiKey]))
            ->post("{$this->baseUrl}/open/account/create", [
                'type' => 1,
                'currency' => 'GHS',
                'accountname' => $this->accountNumber,
            ]);

        return $response->json() ?? [];
    }

    // Sandbox only needs X-API-USER; live additionally needs whichever key the endpoint calls
    // for ($extra), passed in per-call since payment vs status checks use different keys.
    private function headers(array $extra): array
    {
        $headers = ['X-API-USER' => $this->apiUser];
        foreach ($extra as $key => $value) {
            if (!empty($value)) {
                $headers[$key] = $value;
            }
        }
        return $headers;
    }
}
