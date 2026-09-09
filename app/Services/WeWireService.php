<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for WeWire's payments-as-a-service API (https://docs.wewire.com/).
 *
 * Unlike Moolre, WeWire has no hosted-checkout/payment-link product — collection happens by
 * giving a customer a virtual account's bank details (or mobile-money instructions) to
 * transfer into directly. So this service only covers: business sub-customer + KYC
 * onboarding, requesting multi-currency virtual accounts, beneficiaries, and payouts. The
 * actual "payment link" experience is a Meridian-hosted page (see WeWirePaymentController)
 * built entirely on top of these primitives.
 *
 * Each method returns WeWire's decoded JSON body as-is — no persistence happens here, that's
 * the caller's job (see WeWireOnboardingController, WeWireAccountController).
 */
class WeWireService
{
    private string $baseUrl;
    private ?string $apiKey;
    private ?string $webhookSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.wewire.base_url'), '/');
        $this->apiKey = config('services.wewire.api_key');
        $this->webhookSecret = config('services.wewire.webhook_secret');
    }

    // POST /v1/subcustomers — registers the company itself as a WeWire BUSINESS sub-customer.
    public function createSubCustomer(array $businessData): array
    {
        return $this->post('/v1/subcustomers', array_merge(['type' => 'BUSINESS'], $businessData));
    }

    // GET /v1/subcustomers/{id}/kyc-requirements — what this business still needs to submit.
    public function getKycRequirements(string $subCustomerId): array
    {
        return $this->get("/v1/subcustomers/{$subCustomerId}/kyc-requirements");
    }

    // POST /v1/subcustomers/{id}/documents — uploads one KYC document, returns a fileId to
    // reference in submitBusinessKyc()'s questionnaire fields.
    public function uploadDocument(string $subCustomerId, string $docType, string $fileDataUri): array
    {
        return $this->post("/v1/subcustomers/{$subCustomerId}/documents", [
            'docType' => $docType,
            'file' => $fileDataUri,
            'idempotencyKey' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    // POST /v1/subcustomers/{id}/kyc — submits company details + questionnaire (type=BUSINESS).
    public function submitBusinessKyc(string $subCustomerId, array $companyDetails, array $questionnaire): array
    {
        return $this->post("/v1/subcustomers/{$subCustomerId}/kyc", [
            'type' => 'BUSINESS',
            'data' => [
                'company' => $companyDetails,
                'questionnaire' => $questionnaire,
            ],
        ]);
    }

    // POST /v1/subcustomers/{id}/beneficial-owners
    public function addBeneficialOwner(string $subCustomerId, array $ownerData): array
    {
        return $this->post("/v1/subcustomers/{$subCustomerId}/beneficial-owners", array_merge($ownerData, [
            'idempotencyKey' => (string) \Illuminate\Support\Str::uuid(),
        ]));
    }

    // POST /v1/subcustomers/{id}/kyc/submit — moves the sub-customer to IN_REVIEW.
    public function submitKycForReview(string $subCustomerId): array
    {
        return $this->post("/v1/subcustomers/{$subCustomerId}/kyc/submit", []);
    }

    // POST /v1/subcustomers/{id}/accounts/request — requests one currency's virtual account.
    // sourceOfFunds is only required when currency is USD.
    public function requestVirtualAccount(string $subCustomerId, string $currency, ?string $sourceOfFunds = null): array
    {
        $payload = ['currency' => $currency];
        if ($sourceOfFunds) {
            $payload['sourceOfFunds'] = $sourceOfFunds;
        }
        return $this->post("/v1/subcustomers/{$subCustomerId}/accounts/request", $payload);
    }

    // POST /v1/beneficiaries — registers a payout destination bank account.
    public function createBeneficiary(array $beneficiaryData): array
    {
        return $this->post('/v1/beneficiaries', $beneficiaryData);
    }

    // POST /v1/transactions/initiate-payout — disburses held funds to a beneficiary account.
    // idempotencyKey is generated here (not left to the caller) so a retried disbursement for
    // the same inbound payment never double-pays.
    public function initiatePayout(array $payoutData): array
    {
        return $this->post('/v1/transactions/initiate-payout', array_merge([
            'idempotencyKey' => (string) \Illuminate\Support\Str::uuid(),
        ], $payoutData));
    }

    // GET /v1/transactions/{id}
    public function getTransaction(string $transactionId): array
    {
        return $this->get("/v1/transactions/{$transactionId}");
    }

    /**
     * Verifies the `webhook-signature` header on an inbound WeWire webhook (see
     * https://docs.wewire.com/working-with-the-api/webhooks): HMAC-SHA256, base64-encoded,
     * over "{webhook-id}.{webhook-timestamp}.{raw body}", keyed by the base64 portion of the
     * whsec_-prefixed webhook secret. Also rejects deliveries whose timestamp has drifted more
     * than 5 minutes, to block replay of a captured payload.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        if (!$this->webhookSecret) {
            return false;
        }

        $id = $request->header('webhook-id');
        $timestamp = $request->header('webhook-timestamp');
        $signatureHeader = $request->header('webhook-signature');

        if (!$id || !$timestamp || !$signatureHeader) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $secret = base64_decode(preg_replace('/^whsec_/', '', $this->webhookSecret));
        $signedContent = "{$id}.{$timestamp}.{$request->getContent()}";
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

        foreach (explode(' ', $signatureHeader) as $candidate) {
            $candidate = preg_replace('/^v1,/', '', $candidate);
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function get(string $path): array
    {
        $response = Http::withHeaders($this->headers())->get("{$this->baseUrl}{$path}");
        return $response->json() ?? [];
    }

    private function post(string $path, array $payload): array
    {
        $response = Http::withHeaders($this->headers())->post("{$this->baseUrl}{$path}", $payload);
        return $response->json() ?? [];
    }

    private function headers(): array
    {
        return ['ww-api-key' => $this->apiKey];
    }
}
