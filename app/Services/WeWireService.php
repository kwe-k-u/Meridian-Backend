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
    private bool $simulate;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.wewire.base_url'), '/');
        $this->apiKey = config('services.wewire.api_key');
        $this->webhookSecret = config('services.wewire.webhook_secret');
        // SIMULATED — see WEWIRE_SIMULATE. WeWire's KYC and beneficiary-creation endpoints are
        // currently broken on their end, so createSubCustomer/submitBusinessKyc/
        // addBeneficialOwner/submitKycForReview/createBeneficiary below fake a success response
        // instead of calling out. requestVirtualAccount/initiatePayout/everything else are
        // untouched and still hit the real API. Flip WEWIRE_SIMULATE=false once WeWire's side
        // is fixed — see the memory note left on this for the full picture.
        $this->simulate = (bool) config('services.wewire.simulate');
    }

    // POST /v1/subcustomers — registers the company itself as a WeWire BUSINESS sub-customer.
    public function createSubCustomer(array $businessData): array
    {
        if ($this->simulate) {
            return [
                'id' => 'SIM_SUB_' . strtoupper(\Illuminate\Support\Str::random(10)),
                'status' => 'ACTIVE',
                'onboardingStatus' => 'DRAFT',
            ];
        }

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
        if ($this->simulate) {
            return []; // SIMULATED — success, no error keys (see WeWireOnboardingController::submitKyc).
        }

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
        if ($this->simulate) {
            return []; // SIMULATED — success, no error keys.
        }

        return $this->post("/v1/subcustomers/{$subCustomerId}/beneficial-owners", array_merge($ownerData, [
            'idempotencyKey' => (string) \Illuminate\Support\Str::uuid(),
        ]));
    }

    // POST /v1/subcustomers/{id}/kyc/submit — moves the sub-customer to IN_REVIEW.
    public function submitKycForReview(string $subCustomerId): array
    {
        if ($this->simulate) {
            // SIMULATED — sits in IN_REVIEW forever since no webhook will ever confirm it;
            // Settings.tsx's KYC banner is softened accordingly rather than treating this as blocking.
            return ['onboardingStatus' => 'IN_REVIEW'];
        }

        return $this->post("/v1/subcustomers/{$subCustomerId}/kyc/submit", []);
    }

    // POST /v1/subcustomers/{id}/accounts/request — requests one currency's virtual account.
    // sourceOfFunds is only required when currency is USD. $confirmSimulated skips the live
    // call entirely and returns a fabricated success — see liveCall()'s docblock for the
    // two-phase confirm flow this supports.
    public function requestVirtualAccount(string $subCustomerId, string $currency, ?string $sourceOfFunds = null, bool $confirmSimulated = false): array
    {
        $payload = ['currency' => $currency];
        if ($sourceOfFunds) {
            $payload['sourceOfFunds'] = $sourceOfFunds;
        }

        return $this->liveCall('post', "/v1/subcustomers/{$subCustomerId}/accounts/request", $payload, function () use ($currency) {
            return [
                'id' => 'SIM_ACC_' . strtoupper(\Illuminate\Support\Str::random(10)),
                'currency' => $currency,
                'status' => 'ACTIVE',
                'accountNumber' => 'SIM' . random_int(1000000000, 9999999999),
            ];
        }, $confirmSimulated, fn (array $data) => isset($data['id']));
    }

    // GET /v1/subcustomers/{subCustomerId}/accounts/{accountId} — the real-time status of an
    // already-requested virtual account (https://docs.wewire.com/api-reference/
    // sub-customer-accounts/get-account). Used to verify an account is genuinely ACTIVE on
    // WeWire's side right before a customer is told to pay into it — see
    // WeWirePaymentController::attemptPublicPayment. $confirmSimulated — see
    // requestVirtualAccount() above.
    public function getVirtualAccount(string $subCustomerId, string $accountId, bool $confirmSimulated = false): array
    {
        return $this->liveCall('get', "/v1/subcustomers/{$subCustomerId}/accounts/{$accountId}", [], function () use ($accountId) {
            return ['id' => $accountId, 'status' => 'ACTIVE'];
        }, $confirmSimulated, fn (array $data) => isset($data['status']));
    }

    // POST /v1/beneficiaries — registers a payout destination bank account. $confirmSimulated —
    // see requestVirtualAccount() above; only takes effect once WEWIRE_SIMULATE is off (below),
    // since a call gated by that flag never reaches WeWire in the first place.
    public function createBeneficiary(array $beneficiaryData, bool $confirmSimulated = false): array
    {
        if ($this->simulate) {
            // SIMULATED — see the constructor comment. Every other beneficiary-adjacent flow
            // (virtual accounts, payouts) still calls the real API; only account *creation* is faked.
            return ['id' => 'SIM_BEN_' . strtoupper(\Illuminate\Support\Str::random(10))];
        }

        return $this->liveCall('post', '/v1/beneficiaries', $beneficiaryData, function () {
            return ['id' => 'SIM_BEN_' . strtoupper(\Illuminate\Support\Str::random(10))];
        }, $confirmSimulated, fn (array $data) => isset($data['id']));
    }

    // POST /v1/transactions/initiate-payout — disburses held funds to a beneficiary account.
    // idempotencyKey is generated here (not left to the caller) so a retried disbursement for
    // the same inbound payment never double-pays. $confirmSimulated — see requestVirtualAccount().
    public function initiatePayout(array $payoutData, bool $confirmSimulated = false): array
    {
        return $this->liveCall('post', '/v1/transactions/initiate-payout', array_merge([
            'idempotencyKey' => (string) \Illuminate\Support\Str::uuid(),
        ], $payoutData), function () use ($payoutData) {
            return [
                'id' => 'SIM_TXN_' . strtoupper(\Illuminate\Support\Str::random(10)),
                'status' => 'SUCCESSFUL',
                'amount' => $payoutData['amount'] ?? null,
                'fee' => 0,
            ];
        }, $confirmSimulated, fn (array $data) => isset($data['id']));
    }

    // GET /v1/transactions/{id}
    public function getTransaction(string $transactionId): array
    {
        return $this->get("/v1/transactions/{$transactionId}");
    }

    // GET /v1/rates/pair — near real-time bid/ask exchange rate between two currencies (see
    // https://docs.wewire.com/api-reference/rates/get-pair-rate). Used by CurrencyController to
    // replace the hardcoded CurrencyService table with live rates. Not wrapped in liveCall()
    // since this isn't a money-moving call — a failure here should just fall back to the
    // hardcoded table, which the controller handles itself.
    public function getPairRate(string $from, string $to): array
    {
        return $this->get('/v1/rates/pair?' . http_build_query(['from' => $from, 'to' => $to]));
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

    /**
     * Two-phase fallback wrapper for WeWire calls that are on the live money-moving path
     * (currently requestVirtualAccount/initiatePayout — see their callers in
     * WeWireAccountController/WeWirePaymentController): WeWire's staging API is flaky enough
     * that a failed call shouldn't just dead-end the dashboard.
     *
     * Phase 1 ($confirmSimulated = false, the normal case): call WeWire for real. On success,
     * return its body as-is (merged with `_wewire_meta.source = 'live'`). On failure (non-2xx,
     * a thrown connection exception, OR a 2xx response that $isUsable rejects — e.g. WeWire
     * accepting the HTTP request but replying with something like "business KYC still in
     * review" instead of an actual account id), DO NOT fabricate anything silently — return
     * `_wewire_meta.source = 'simulated_fallback'` plus the real error AND a proposed simulated
     * body, so the controller can hand both to the frontend without persisting/committing
     * anything yet. The frontend shows the "Response from wewire server" popup and asks the
     * user whether to proceed with the simulated result.
     *
     * Phase 2 ($confirmSimulated = true): the frontend resubmits the same request after the
     * user accepted the popup. Skips the live call entirely and returns the simulator's output
     * tagged `_wewire_meta.source = 'simulated_confirmed'`, which the controller then persists
     * exactly like a real success (just flagged `is_simulated` for the audit trail).
     *
     * $isUsable optionally inspects a *successful* HTTP response's decoded body and returns
     * false to still route it into the fallback path (default: any 2xx counts as usable).
     */
    private function liveCall(string $method, string $path, array $payload, \Closure $simulate, bool $confirmSimulated = false, ?\Closure $isUsable = null): array
    {
        if ($confirmSimulated) {
            return array_merge($simulate(), ['_wewire_meta' => ['source' => 'simulated_confirmed']]);
        }

        try {
            $response = $method === 'get'
                ? Http::withHeaders($this->headers())->get("{$this->baseUrl}{$path}")
                : Http::withHeaders($this->headers())->post("{$this->baseUrl}{$path}", $payload);
        } catch (\Throwable $e) {
            return array_merge($simulate(), [
                '_wewire_meta' => ['source' => 'simulated_fallback', 'error' => ['status' => null, 'body' => $e->getMessage()]],
            ]);
        }

        if ($response->successful()) {
            $data = $response->json();
            $data = is_array($data) ? $data : [];

            if (!$isUsable || $isUsable($data)) {
                return array_merge($data, ['_wewire_meta' => ['source' => 'live']]);
            }

            // WeWire accepted the HTTP request but didn't give back anything we can actually
            // use (e.g. blocked pending business/KYC review) — same fallback UX as an outright
            // failure, since from the dashboard's point of view it's equally a dead end.
            return array_merge($simulate(), [
                '_wewire_meta' => ['source' => 'simulated_fallback', 'error' => ['status' => $response->status(), 'body' => $data]],
            ]);
        }

        return array_merge($simulate(), [
            '_wewire_meta' => [
                'source' => 'simulated_fallback',
                'error' => ['status' => $response->status(), 'body' => $response->json() ?? $response->body()],
            ],
        ]);
    }

    private function headers(): array
    {
        return ['ww-api-key' => $this->apiKey];
    }
}
