<?php

namespace App\Http\Controllers;

use App\Enums\DisbursementStatus;
use App\Enums\FundHandling;
use App\Enums\InboundMatchStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Enums\TransactionStatus;
use App\Enums\VirtualAccountStatus;
use App\Enums\WeWireKycStatus;
use App\Helpers\UserHelper;
use App\Mail\PaymentConfirmationMail;
use App\Models\Installment;
use App\Models\InstallmentPayment;
use App\Models\PaymentPlan;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\WeWireBeneficiary;
use App\Models\WeWireDisbursement;
use App\Models\WeWireInboundTransaction;
use App\Models\WeWireVirtualAccount;
use App\Services\IdGeneratorService;
use App\Services\WeWireService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * The customer-facing collection page's backend, and the WeWire webhook receiver.
 *
 * WeWire has no hosted payment-link product (see App\Services\WeWireService docblock), so
 * "paying a trip" means: the customer looks their trip up by an 8-char reference code
 * (App\Models\PaymentPlan.payment_reference), we show them the company's WeWire virtual
 * account bank details for that currency, and they transfer the money themselves outside of
 * any Meridian-driven flow. Confirmation arrives asynchronously via the `transaction.pay_in`
 * webhook, reconciled back to an Installment by matching that same reference code out of
 * whatever narration/reference text WeWire passes through — anything that can't be matched
 * lands in an "unmatched" queue for agency staff to resolve by hand (see matchInbound()).
 *
 * Routes: /api/public/payments/wewire/lookup/{reference} (public),
 * /api/payments/wewire/webhook (public), /api/wewire/inbound, /api/wewire/inbound/{inbound}/match
 * (authenticated).
 */
class WeWirePaymentController extends Controller
{
    // Matches the 8-char reference code format (5 uppercase letters + 3 digits) anywhere
    // inside a string — used both to validate direct lookups and to extract a candidate code
    // out of free-text transfer narration on inbound webhooks.
    private const REFERENCE_PATTERN = '/[A-Z]{5}[0-9]{3}/';

    // GET /api/public/payments/wewire/lookup/{reference} — public, resolves a customer-quoted
    // reference to the plan, its installments/outstanding balance, and the receiving company's
    // active virtual account for the plan's currency.
    public function lookupPublic(string $reference): JsonResponse
    {
        $plan = PaymentPlan::with(['installments', 'trip.company'])
            ->where('payment_reference', strtoupper($reference))
            ->first();

        if (!$plan) {
            return response()->json(['message' => 'We couldn\'t find a payment matching that reference. Double-check the code and try again.'], 404);
        }

        return response()->json($this->buildLookupResponse($plan));
    }

    // Shared by lookupPublic() and simulatePublicPayment() so the "pay now" page and the demo
    // "simulate payment" action return an identically-shaped payload.
    private function buildLookupResponse(PaymentPlan $plan): array
    {
        $account = WeWireVirtualAccount::where('company_id', $plan->trip->company_id)
            ->where('currency', $plan->currency)
            ->where('status', VirtualAccountStatus::ACTIVE->value)
            ->first();

        $installments = $plan->installments->map(function (Installment $installment) {
            $paid = $installment->paidAmount();
            return [
                'id' => $installment->id,
                'sequence' => $installment->sequence,
                'amount' => $installment->amount,
                'due_date' => $installment->due_date,
                'status' => $installment->status,
                'paid_amount' => $paid,
                'outstanding' => round($installment->amount - $paid, 2),
            ];
        });

        return [
            'payment_reference' => $plan->payment_reference,
            'trip_name' => $plan->trip->trip_name,
            'company_name' => $plan->trip->company->company_name,
            'total_amount' => $plan->total_amount,
            'currency' => $plan->currency,
            'outstanding' => round($installments->sum('outstanding'), 2),
            'status' => $plan->status,
            'installments' => $installments,
            'payment_account' => $account ? [
                'currency' => $account->currency,
                'account_number' => $account->account_number,
                'iban' => $account->iban,
                'sort_code' => $account->sort_code,
                'routing_number' => $account->routing_number,
            ] : null,
        ];
    }

    // POST /api/public/payments/wewire/simulate/{reference} — public, the "Proceed with
    // payment" button on the /pay/{reference} page. Two-phase, same pattern as
    // WeWireAccountController::store / WeWirePaymentController::initiateDisbursement:
    //
    // Phase 1 ($confirmSimulated = false, the normal case): actually asks WeWire whether the
    // receiving virtual account is genuinely ACTIVE right now (WeWireService::
    // getVirtualAccount) — there's no WeWire API to "make" a bank-transfer payment happen (see
    // class docblock), so this live check is the real thing being attempted here. If it comes
    // back verified, nothing is simulated: the response just confirms the account is ready and
    // the customer should go ahead and transfer for real (the webhook will confirm it later).
    // If the live check fails or the account isn't really active (e.g. one that only exists
    // because WeWireAccountController::store's own fallback was used to force it through), this
    // returns 409 with the "Response from wewire server" popup payload instead of pretending to
    // pay.
    //
    // Phase 2 ($confirmSimulated = true): the customer accepted that popup. Settles every
    // outstanding installment in full, one simulated inbound transaction at a time (flagged
    // is_simulated), through the same reconcile() path a real webhook uses — so it sends the
    // same confirmation email and triggers the same auto-disbursement. Gated behind
    // WEWIRE_SIMULATE so a real production customer can never fabricate their own payment.
    public function simulatePublicPayment(Request $request, string $reference): JsonResponse
    {
        $plan = PaymentPlan::with(['installments', 'trip.company'])
            ->where('payment_reference', strtoupper($reference))
            ->first();

        if (!$plan) {
            return response()->json(['message' => 'We couldn\'t find a payment matching that reference. Double-check the code and try again.'], 404);
        }

        $account = WeWireVirtualAccount::where('company_id', $plan->trip->company_id)
            ->where('currency', $plan->currency)
            ->where('status', VirtualAccountStatus::ACTIVE->value)
            ->first();

        if (!$account) {
            return response()->json(['message' => "Your agency hasn't finished setting up payments in {$plan->currency} yet."], 422);
        }

        $confirmSimulated = $request->boolean('confirm_simulated');

        if (!$confirmSimulated) {
            $company = $plan->trip->company;

            if (!$company->wewire_subcustomer_id || !$account->wewire_account_id) {
                $liveMeta = ['source' => 'simulated_fallback', 'error' => ['status' => null, 'body' => 'No WeWire account reference on file for this company.']];
            } else {
                $wewire = app(WeWireService::class);
                $result = $wewire->getVirtualAccount($company->wewire_subcustomer_id, $account->wewire_account_id);
                $liveMeta = $result['_wewire_meta'] ?? ['source' => 'live'];
                $liveMeta['status'] = strtoupper($result['status'] ?? '');
            }

            if (($liveMeta['source'] ?? null) === 'live' && ($liveMeta['status'] ?? null) === strtoupper(VirtualAccountStatus::ACTIVE->value)) {
                // Genuinely verified with WeWire — nothing to simulate, tell the customer to
                // go ahead and make the real transfer.
                return response()->json(array_merge($this->buildLookupResponse($plan), ['verified' => true]));
            }

            Log::warning('WeWire virtual account failed live verification on the public pay page', [
                'payment_reference' => $plan->payment_reference,
                'account_id' => $account->id,
                'meta' => $liveMeta,
            ]);

            return response()->json([
                'requires_confirmation' => true,
                'title' => 'Response from wewire server',
                'message' => ($liveMeta['source'] ?? null) === 'live'
                    ? "WeWire reports this account isn't active yet (status: {$liveMeta['status']}). You can proceed with a simulated payment instead."
                    : 'WeWire did not confirm this account is ready to receive payment. You can proceed with a simulated payment instead.',
                'error' => $liveMeta['error'] ?? ['status' => 200, 'body' => ['status' => $liveMeta['status'] ?? null]],
            ], 409);
        }

        if (!config('services.wewire.simulate')) {
            return response()->json(['message' => 'Simulated payments are only available while WeWire is in simulation mode.'], 403);
        }

        $outstanding = $plan->installments->first(fn(Installment $i) => $i->paidAmount() < $i->amount);
        while ($outstanding) {
            $amount = round($outstanding->amount - $outstanding->paidAmount(), 2);

            $inbound = WeWireInboundTransaction::create([
                'id' => IdGeneratorService::generateId('WIT'),
                'wewire_transaction_id' => IdGeneratorService::generateId('SIM'),
                'virtual_account_id' => $account->id,
                'amount' => $amount,
                'currency' => $plan->currency,
                'reference_raw' => $plan->payment_reference,
                'matched_payment_reference' => $plan->payment_reference,
                'status' => InboundMatchStatus::UNMATCHED->value,
                'is_simulated' => true,
                'received_at' => now(),
            ]);

            $this->reconcile($inbound, $outstanding);

            $plan->refresh();
            $plan->load('installments');
            $outstanding = $plan->installments->first(fn(Installment $i) => $i->paidAmount() < $i->amount);
        }

        return response()->json($this->buildLookupResponse($plan->fresh(['installments', 'trip.company'])));
    }

    // POST /api/payments/wewire/webhook — Public. WeWire has no way to send our bearer token,
    // so every delivery is verified via HMAC signature (see WeWireService::verifyWebhookSignature)
    // rather than trusted on the URL alone. Always returns 200 once verified (even for events we
    // don't act on) so WeWire doesn't endlessly retry; a bad/missing signature is the only 4xx.
    public function webhook(Request $request, WeWireService $wewire): JsonResponse
    {
        if (!$wewire->verifyWebhookSignature($request)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $eventType = $request->input('eventType');
        $data = $request->input('data', []);

        match ($eventType) {
            'transaction.pay_in' => $this->handlePayIn($data),
            'transaction.status_updated' => $this->handleTransactionStatusUpdated($data),
            'virtual_account.status_updated' => $this->handleAccountStatusUpdated($data),
            'subcustomer.kyc_status_updated' => $this->handleKycStatusUpdated($data),
            default => Log::info('Unhandled WeWire webhook event', ['eventType' => $eventType]),
        };

        return response()->json(['message' => 'ok']);
    }

    // Records the inbound transfer (idempotent on WeWire's own transaction id) and attempts to
    // auto-reconcile it to an installment by extracting a reference code from whatever
    // narration/reference text the payload carries. Leaves it UNMATCHED for the admin queue
    // when no live reference is found.
    private function handlePayIn(array $data): void
    {
        $wewireTransactionId = $data['id'] ?? $data['transactionId'] ?? null;
        if (!$wewireTransactionId) {
            Log::warning('WeWire pay_in webhook missing transaction id', ['data' => $data]);
            return;
        }

        if (WeWireInboundTransaction::where('wewire_transaction_id', $wewireTransactionId)->exists()) {
            return; // Already processed this delivery.
        }

        $accountIdentifier = $data['accountId'] ?? $data['virtualAccountId'] ?? $data['walletId'] ?? null;
        $account = $accountIdentifier ? WeWireVirtualAccount::where('wewire_account_id', $accountIdentifier)->first() : null;

        $rawReference = $data['reference'] ?? $data['narration'] ?? $data['description'] ?? '';
        preg_match(self::REFERENCE_PATTERN, strtoupper($rawReference), $matches);
        $matchedCode = $matches[0] ?? null;

        $inbound = WeWireInboundTransaction::create([
            'id' => IdGeneratorService::generateId('WIT'),
            'wewire_transaction_id' => $wewireTransactionId,
            'virtual_account_id' => $account?->id,
            'amount' => $data['amount'] ?? 0,
            'currency' => $data['currency'] ?? $account?->currency ?? 'USD',
            'reference_raw' => $rawReference ?: null,
            'matched_payment_reference' => $matchedCode,
            'status' => InboundMatchStatus::UNMATCHED->value,
            'received_at' => now(),
        ]);

        if ($matchedCode) {
            $plan = PaymentPlan::with('installments')->where('payment_reference', $matchedCode)->first();
            if ($plan) {
                $this->reconcile($inbound, $this->pickInstallmentToApply($plan, (float) $inbound->amount));
                return;
            }
        }

        Log::info('WeWire inbound transfer could not be auto-matched', ['inbound_id' => $inbound->id, 'reference_raw' => $rawReference]);
    }

    // Picks which outstanding installment an inbound amount should apply to: the earliest
    // (lowest sequence) installment that isn't fully paid yet.
    private function pickInstallmentToApply(PaymentPlan $plan, float $amount): ?Installment
    {
        return $plan->installments->first(fn(Installment $i) => $i->paidAmount() < $i->amount);
    }

    // POST /api/wewire/inbound/{inbound}/match — staff manually assign an unmatched inbound
    // transfer to a specific installment.
    public function matchInbound(Request $request, WeWireInboundTransaction $inbound): JsonResponse
    {
        $company = UserHelper::user_company($request);

        $validated = $request->validate([
            'installment_id' => 'required|string|exists:installments,id',
        ]);

        $installment = Installment::with('paymentPlan.trip')->find($validated['installment_id']);
        if (!$installment || $installment->paymentPlan->trip->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($inbound->status !== InboundMatchStatus::UNMATCHED) {
            return response()->json(['message' => 'This transfer has already been matched.'], 422);
        }

        $this->reconcile($inbound, $installment);

        return response()->json($inbound->fresh());
    }

    // Shared completion path for both auto-match (handlePayIn) and manual match
    // (matchInbound): creates the completed Transaction + InstallmentPayment, rolls the
    // Installment/PaymentPlan status forward, emails a receipt, and — if the receiving
    // account is set to disburse — kicks off a WeWire payout to its beneficiary.
    private function reconcile(WeWireInboundTransaction $inbound, ?Installment $installment): void
    {
        if (!$installment) {
            Log::info('WeWire inbound transfer matched a reference with no outstanding installment', ['inbound_id' => $inbound->id]);
            return;
        }

        DB::transaction(function () use ($inbound, $installment) {
            $transactionId = IdGeneratorService::generateId('TXN');

            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'amount' => $inbound->amount,
                'currency' => $inbound->currency,
                'payment_method' => 'wewire',
                'transaction_reference' => $inbound->wewire_transaction_id,
                'status' => TransactionStatus::COMPLETED->value,
                'paid_at' => $inbound->received_at,
            ]);

            InstallmentPayment::create([
                'transaction_id' => $transactionId,
                'installment_id' => $installment->id,
            ]);

            $inbound->update([
                'installment_id' => $installment->id,
                'transaction_id' => $transactionId,
                'status' => InboundMatchStatus::RECONCILED->value,
            ]);

            $installment->refresh();
            $paid = $installment->paidAmount();
            $installment->update([
                'status' => $paid + 0.01 >= $installment->amount ? InstallmentStatus::PAID->value : InstallmentStatus::PARTIALLY_PAID->value,
            ]);

            $plan = $installment->paymentPlan()->with('installments')->first();
            if ($plan && $plan->installments->every(fn(Installment $i) => $i->status === InstallmentStatus::PAID)) {
                $plan->update(['status' => PaymentPlanStatus::COMPLETED->value]);
            }
        });

        $this->sendPaymentConfirmationEmail($installment);
        $this->maybeDisburse($inbound);
    }

    private function sendPaymentConfirmationEmail(Installment $installment): void
    {
        $installment->loadMissing('paymentPlan.trip.customers', 'paymentPlan.trip.company');
        $trip = $installment->paymentPlan->trip ?? null;
        if (!$trip) {
            return;
        }

        foreach ($trip->customers as $customer) {
            if (!$customer->email) {
                continue;
            }
            try {
                Mail::to($customer->email)->send(new PaymentConfirmationMail(
                    trim("{$customer->first_name} {$customer->last_name}") ?: 'Traveler',
                    $trip->trip_name,
                    $trip->company->company_name ?? 'Meridian',
                    (float) $installment->amount,
                    $installment->currency,
                ));
            } catch (Exception $e) {
                Log::error('Failed to send WeWire payment confirmation email', ['installment_id' => $installment->id, 'error' => $e->getMessage()]);
            }
        }
    }

    // If the receiving virtual account is set to DISBURSE rather than HOLD, pays the funds
    // straight out to its linked beneficiary. Unlike the old fire-and-forget version, every
    // attempt is recorded as a WeWireDisbursement row (see initiateDisbursement()) so a failed
    // or never-tracked payout is visible in the reconciliation queue rather than only a log line.
    private function maybeDisburse(WeWireInboundTransaction $inbound): void
    {
        $account = $inbound->virtualAccount()->with('beneficiary')->first();
        if (!$account || $account->fund_handling !== FundHandling::DISBURSE || !$account->beneficiary) {
            return;
        }

        $this->initiateDisbursement($account, $account->beneficiary, (float) $inbound->amount, $inbound->currency, ['source_inbound_id' => $inbound->id]);
    }

    // Calls WeWire's initiate-payout, then creates the WeWireDisbursement audit row once the
    // outcome is known. If WeWire never even accepts the request (no `id` in the response,
    // including the fallback-declined case), the row is marked INITIATION_FAILED immediately —
    // there's no wewire_transaction_id for a later webhook to ever update, so this is the only
    // chance to record the failure reason.
    //
    // $source is either ['source_inbound_id' => ...] (automatic, one inbound payment) or
    // ['source_trip_id' => ...] (manual, sweeps up everything collected on a trip — see
    // payoutTrip()). Exactly one key is expected; the other column stays null. Set
    // $source['interactive'] = true for HTTP-triggered calls (payoutTrip/retryDisbursement) —
    // when WeWire's live call fails, those get a chance to show the "Response from wewire
    // server" popup and resubmit with $confirmSimulated before anything is persisted.
    // maybeDisburse() (webhook-triggered, no user to ask) omits it and just falls through to
    // recording an INITIATION_FAILED row, same as before this fallback existed.
    //
    // Returns ['disbursement' => WeWireDisbursement, 'confirmation' => null] once resolved, or
    // ['disbursement' => null, 'confirmation' => array] when an interactive caller needs to
    // show the popup instead.
    private function initiateDisbursement(WeWireVirtualAccount $account, WeWireBeneficiary $beneficiary, float $amount, string $currency, array $source, bool $confirmSimulated = false): array
    {
        $interactive = (bool) ($source['interactive'] ?? false);
        unset($source['interactive']);

        $disbursementId = IdGeneratorService::generateId('WWD');

        // WeWire's real validator rejects underscores in `reference` (IdGeneratorService's
        // ids are PREFIX_HEXRANDOM) and requires `description` — both confirmed against a real
        // 400 from /v1/transactions/initiate-payout, not just the docs.
        $description = isset($source['line_item_label'])
            ? "Meridian payout — {$source['line_item_label']}"
            : 'Meridian trip payout';

        $wewire = app(WeWireService::class);
        $result = $wewire->initiatePayout([
            'from' => $currency,
            'to' => $beneficiary->currency,
            'amount' => $amount,
            'beneficiaryAccountId' => $beneficiary->wewire_beneficiary_id,
            'reference' => str_replace('_', '-', $disbursementId),
            'description' => $description,
        ], $confirmSimulated);

        $wewireSource = $result['_wewire_meta']['source'] ?? 'live';

        if ($wewireSource === 'simulated_fallback' && $interactive) {
            Log::warning('WeWire disbursement initiation failed — offering simulated fallback', [
                'virtual_account_id' => $account->id,
                'beneficiary_id' => $beneficiary->id,
                'error' => $result['_wewire_meta']['error'],
            ]);

            return ['disbursement' => null, 'confirmation' => [
                'requires_confirmation' => true,
                'title' => 'Response from wewire server',
                'message' => 'WeWire did not accept this payout. You can proceed with a simulated result instead.',
                'error' => $result['_wewire_meta']['error'],
                'simulated' => Arr::except($result, ['_wewire_meta']),
            ]];
        }

        $disbursement = WeWireDisbursement::create(array_merge([
            'id' => $disbursementId,
            'virtual_account_id' => $account->id,
            'beneficiary_id' => $beneficiary->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => DisbursementStatus::PENDING->value,
            'is_simulated' => $wewireSource === 'simulated_confirmed',
            'initiated_at' => now(),
        ], $source));

        if (isset($result['id']) && in_array($wewireSource, ['live', 'simulated_confirmed'], true)) {
            $disbursement->update([
                'wewire_transaction_id' => $result['id'],
                'status' => isset($result['status']) ? strtolower($result['status']) : DisbursementStatus::PENDING->value,
                'fee' => $result['fee'] ?? null,
            ]);
        } else {
            Log::warning('WeWire disbursement initiation rejected', ['disbursement_id' => $disbursement->id, 'response' => $result]);
            $errorBody = $result['_wewire_meta']['error']['body'] ?? null;
            $disbursement->update([
                'status' => DisbursementStatus::INITIATION_FAILED->value,
                'failure_reason' => is_array($errorBody) ? json_encode($errorBody) : ($errorBody ?? ($result['message'] ?? 'WeWire did not accept the payout request.')),
            ]);
        }

        return ['disbursement' => $disbursement->fresh(), 'confirmation' => null];
    }

    // Applies a `transaction.status_updated` webhook. WeWire fires this for both payouts and
    // collections (see docs.wewire.com/working-with-the-api/webhooks), so this checks both:
    // a matching WeWireDisbursement first, then falls back to a collection-side reversal.
    private function handleTransactionStatusUpdated(array $data): void
    {
        $wewireTransactionId = $data['id'] ?? $data['transactionId'] ?? null;
        $status = $data['status'] ?? null;
        if (!$wewireTransactionId || !$status) {
            return;
        }

        $disbursement = WeWireDisbursement::where('wewire_transaction_id', $wewireTransactionId)->first();
        if ($disbursement) {
            $this->applyDisbursementStatus($disbursement, $data);
            return;
        }

        $inbound = WeWireInboundTransaction::where('wewire_transaction_id', $wewireTransactionId)->first();
        if ($inbound) {
            $this->applyInboundStatus($inbound, $data);
        }
    }

    private function applyDisbursementStatus(WeWireDisbursement $disbursement, array $data): void
    {
        $mapped = match (strtoupper($data['status'])) {
            'SUCCESSFUL' => DisbursementStatus::SUCCESSFUL,
            'FAILED' => DisbursementStatus::FAILED,
            'REVERSED' => DisbursementStatus::REVERSED,
            'CANCELLED' => DisbursementStatus::CANCELLED,
            'PENDING' => DisbursementStatus::PENDING,
            default => null,
        };
        if (!$mapped) {
            return;
        }

        $disbursement->update(array_filter([
            'status' => $mapped->value,
            'fee' => $data['fee'] ?? $disbursement->fee,
            'settled_at' => $data['settledAt'] ?? ($mapped === DisbursementStatus::SUCCESSFUL ? now() : $disbursement->settled_at),
        ], fn($v) => $v !== null));
    }

    // A collection transaction we already reconciled (see reconcile()) can later be reported
    // REVERSED (e.g. a bank recall/chargeback). Flip the underlying Transaction to `refunded` —
    // Installment::paidAmount() only sums `completed` transactions, so this alone unwinds the
    // installment/plan status without needing to duplicate that arithmetic here.
    private function applyInboundStatus(WeWireInboundTransaction $inbound, array $data): void
    {
        if (strtoupper($data['status']) !== 'REVERSED' || !$inbound->transaction_id) {
            return;
        }

        $transaction = Transaction::find($inbound->transaction_id);
        if (!$transaction || $transaction->status !== TransactionStatus::COMPLETED) {
            return;
        }

        DB::transaction(function () use ($transaction, $inbound) {
            $transaction->update(['status' => TransactionStatus::REFUNDED->value]);

            $installment = $inbound->installment()->first();
            if (!$installment) {
                return;
            }

            $installment->refresh();
            $paid = $installment->paidAmount();
            $installment->update([
                'status' => $paid <= 0.01
                    ? InstallmentStatus::PENDING->value
                    : ($paid + 0.01 >= $installment->amount ? InstallmentStatus::PAID->value : InstallmentStatus::PARTIALLY_PAID->value),
            ]);

            $plan = $installment->paymentPlan()->first();
            if ($plan && $plan->status === PaymentPlanStatus::COMPLETED) {
                $plan->update(['status' => PaymentPlanStatus::ACTIVE->value]);
            }
        });

        Log::warning('WeWire reported a reversed collection payment', ['inbound_id' => $inbound->id, 'transaction_id' => $transaction->transaction_id]);
    }

    private function handleAccountStatusUpdated(array $data): void
    {
        $wewireAccountId = $data['id'] ?? null;
        if (!$wewireAccountId) {
            return;
        }

        $account = WeWireVirtualAccount::where('wewire_account_id', $wewireAccountId)->first();
        if (!$account) {
            return;
        }

        $account->update(array_filter([
            'status' => isset($data['status']) ? strtolower($data['status']) : null,
            'account_number' => $data['accountNumber'] ?? $account->account_number,
            'iban' => $data['iban'] ?? $account->iban,
            'sort_code' => $data['sortCode'] ?? $account->sort_code,
            'routing_number' => $data['routingNumber'] ?? $account->routing_number,
        ], fn($v) => $v !== null));
    }

    private function handleKycStatusUpdated(array $data): void
    {
        $subCustomerId = $data['subCustomerId'] ?? $data['id'] ?? null;
        $onboardingStatus = $data['onboardingStatus'] ?? $data['status'] ?? null;
        if (!$subCustomerId || !$onboardingStatus) {
            return;
        }

        $company = \App\Models\Company::where('wewire_subcustomer_id', $subCustomerId)->first();
        if (!$company) {
            return;
        }

        $mapped = match (strtoupper($onboardingStatus)) {
            'APPROVED' => WeWireKycStatus::APPROVED,
            'REJECTED' => WeWireKycStatus::REJECTED,
            'RESUBMISSION' => WeWireKycStatus::RESUBMISSION,
            'IN_REVIEW' => WeWireKycStatus::IN_REVIEW,
            default => null,
        };

        if ($mapped) {
            $company->update(['wewire_kyc_status' => $mapped->value]);
        }
    }

    // GET /api/wewire/inbound — the reconciliation queue (default: unmatched only).
    public function listInbound(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);

        // Scoped to transfers we could attribute to one of this company's accounts. A transfer
        // that arrived with no recognizable accountId (see handlePayIn()) has no reliable
        // owner and isn't surfaced here — that's a superadmin-level gap, not a per-company one.
        $query = WeWireInboundTransaction::whereHas('virtualAccount', fn($q) => $q->where('company_id', $company->company_id))
            ->orderByDesc('received_at');

        if ($request->query('status', 'unmatched') !== 'all') {
            $query->where('status', $request->query('status', InboundMatchStatus::UNMATCHED->value));
        }

        return response()->json($query->with(['virtualAccount', 'installment.paymentPlan.trip'])->paginate(20));
    }

    // GET /api/wewire/disbursements — payout attempt history, newest first. Every retry shows
    // up as its own row (see retryDisbursement()) rather than overwriting the failed one.
    public function listDisbursements(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);

        $query = WeWireDisbursement::whereHas('virtualAccount', fn($q) => $q->where('company_id', $company->company_id))
            ->orderByDesc('initiated_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->with(['virtualAccount', 'beneficiary', 'sourceTrip'])->paginate(20));
    }

    // POST /api/wewire/disbursements/{disbursement}/retry — re-attempts a payout that never
    // reached WeWire or that WeWire later reported FAILED/REVERSED. Creates a fresh
    // WeWireDisbursement row against the same source payment rather than mutating the failed
    // one, so the full attempt history stays visible.
    public function retryDisbursement(Request $request, WeWireDisbursement $disbursement): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $disbursement->loadMissing(['virtualAccount', 'beneficiary']);
        if (!$disbursement->virtualAccount || $disbursement->virtualAccount->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $retryableStatuses = [DisbursementStatus::INITIATION_FAILED, DisbursementStatus::FAILED, DisbursementStatus::REVERSED, DisbursementStatus::CANCELLED];
        if (!in_array($disbursement->status, $retryableStatuses, true)) {
            return response()->json(['message' => 'Only a failed, reversed, or cancelled disbursement can be retried.'], 422);
        }

        $result = $this->initiateDisbursement(
            $disbursement->virtualAccount,
            $disbursement->beneficiary,
            (float) $disbursement->amount,
            $disbursement->currency,
            array_merge(
                $disbursement->source_trip_id
                    ? ['source_trip_id' => $disbursement->source_trip_id]
                    : ['source_inbound_id' => $disbursement->source_inbound_id],
                ['interactive' => true],
            ),
            (bool) $request->boolean('confirm_simulated'),
        );

        if ($result['confirmation']) {
            return response()->json($result['confirmation'], 409);
        }

        return response()->json($result['disbursement'], 201);
    }

    // GET /api/wewire/trip-balances — for the dashboard's "Pay out agency" panel: every trip
    // with a payment plan that has collected money via WeWire and hasn't been fully paid out
    // yet. A trip's "held balance" is bookkeeping on our side (WeWire itself doesn't earmark
    // wallet funds by trip) — completed WeWire installment payments, minus whatever's already
    // been swept out for that trip via a PENDING or SUCCESSFUL WeWireDisbursement.
    public function tripBalances(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);

        // Grouped by trip (not by plan) — a trip can have several plans at once (FULL +
        // INSTALLMENTS defaults, plus an optional CUSTOM one, see Trip::paymentPlans()) and
        // this panel shows one row per trip, summing whatever came in through any of them.
        $trips = Trip::with(['paymentPlans.installments.installmentPayments.transaction'])
            ->where('company_id', $company->company_id)
            ->whereHas('paymentPlans')
            ->get();

        $disbursedByTrip = WeWireDisbursement::whereIn('source_trip_id', $trips->pluck('trip_id'))
            ->whereIn('status', [DisbursementStatus::PENDING, DisbursementStatus::SUCCESSFUL])
            ->get()
            ->groupBy('source_trip_id')
            ->map(fn($rows) => $rows->sum('amount'));

        // Any beneficiary in a matching currency can now be paid out to (see payoutTrip()) —
        // not just the one a virtual account happens to auto-disburse to. Preferring an
        // 'agency'-type one keeps the Dashboard's one-click "pay agency in full" button working
        // without a second lookup; grouped by currency once rather than per-trip.
        $beneficiariesByCurrency = $company->wewireBeneficiaries()->get()->groupBy('currency');
        $activeAccountCurrencies = WeWireVirtualAccount::where('company_id', $company->company_id)
            ->where('status', VirtualAccountStatus::ACTIVE->value)
            ->pluck('currency')->flip();

        $balances = $trips->map(function (Trip $trip) use ($disbursedByTrip, $beneficiariesByCurrency, $activeAccountCurrencies) {
            // All of a trip's plans share one currency — see heldBalanceForTrip()'s comment.
            $currency = $trip->paymentPlans->first()->currency;
            $collected = round($trip->paymentPlans->sum(fn (PaymentPlan $plan) => $this->collectedViaWeWire($plan)), 2);
            $disbursed = (float) ($disbursedByTrip[$trip->trip_id] ?? 0);
            $held = round($collected - $disbursed, 2);

            $candidates = $beneficiariesByCurrency[$currency] ?? collect();
            $beneficiary = $candidates->firstWhere('beneficiary_type', 'agency') ?? $candidates->first();

            return [
                'trip_id' => $trip->trip_id,
                'trip_name' => $trip->trip_name,
                'currency' => $currency,
                'collected' => $collected,
                'held_balance' => $held,
                'beneficiary_id' => $beneficiary?->id,
                'can_payout' => $held > 0.01 && $beneficiary && isset($activeAccountCurrencies[$currency]),
            ];
        })->filter(fn($b) => $b['held_balance'] > 0.01)->values();

        return response()->json($balances);
    }

    // POST /api/trips/{trip}/payout — pays someone out of a trip's held balance: the agency
    // itself (omit line_item_*, matches the original one-click "pay agency" flow from the
    // dashboard) or a specific trip service provider — the airline, hotel, or activity vendor
    // for one line item (see TripPayoutsSection on the frontend). `amount` is optional and
    // defaults to the full currently-held balance; when given, it must not exceed it — the
    // caller chooses exactly how much a given provider gets, which need not match the
    // itinerary's listed cost for that item.
    public function payoutTrip(Request $request, Trip $trip): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($trip->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'beneficiary_id' => 'required|string|exists:wewire_beneficiaries,id',
            'amount' => 'nullable|numeric|min:0.01',
            'line_item_type' => ['nullable', 'string', Rule::in(['flight', 'accommodation', 'activity'])],
            'line_item_id' => 'nullable|string|max:100',
            'line_item_label' => 'nullable|string|max:200',
            'confirm_simulated' => 'nullable|boolean',
        ]);

        $balance = $this->heldBalanceForTrip($trip);
        if (!$balance) {
            return response()->json(['message' => 'This trip has no payment plan / no WeWire collections yet.'], 422);
        }
        ['currency' => $currency, 'held' => $held] = $balance;

        if ($held <= 0.01) {
            return response()->json(['message' => 'Nothing is currently held for this trip.'], 422);
        }

        $amount = isset($validated['amount']) ? (float) $validated['amount'] : $held;
        if ($amount > $held + 0.01) {
            return response()->json(['message' => "Only {$held} {$currency} is currently held for this trip."], 422);
        }

        $beneficiary = WeWireBeneficiary::find($validated['beneficiary_id']);
        if (!$beneficiary || $beneficiary->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if ($beneficiary->currency !== $currency) {
            return response()->json(['message' => "That beneficiary is in {$beneficiary->currency}, but this trip's held balance is in {$currency}."], 422);
        }

        $account = WeWireVirtualAccount::where('company_id', $company->company_id)
            ->where('currency', $currency)
            ->where('status', VirtualAccountStatus::ACTIVE->value)
            ->first();
        if (!$account) {
            return response()->json(['message' => "No active {$currency} virtual account for this company yet."], 422);
        }

        $result = $this->initiateDisbursement($account, $beneficiary, $amount, $currency, array_merge(
            array_filter([
                'source_trip_id' => $trip->trip_id,
                'line_item_type' => $validated['line_item_type'] ?? null,
                'line_item_id' => $validated['line_item_id'] ?? null,
                'line_item_label' => $validated['line_item_label'] ?? null,
            ], fn($v) => $v !== null),
            ['interactive' => true],
        ), (bool) ($validated['confirm_simulated'] ?? false));

        if ($result['confirmation']) {
            return response()->json($result['confirmation'], 409);
        }

        return response()->json($result['disbursement'], 201);
    }

    // Shared by tripBalances() (batch, across many trips) and payoutTrip() (single trip): how
    // much of what's been collected via WeWire on this trip hasn't been paid out yet, across
    // *any* beneficiary/line-item — so paying the agency AND several providers off the same
    // trip can never collectively exceed what was actually collected. A trip can have several
    // payment plans at once (FULL + INSTALLMENTS defaults, plus an optional CUSTOM one — see
    // Trip::paymentPlans()); money collected through any of them counts. Returns null if the
    // trip has no payment plan at all yet (nothing to collect through).
    private function heldBalanceForTrip(Trip $trip): ?array
    {
        $plans = $trip->paymentPlans()->with('installments.installmentPayments.transaction')->get();
        if ($plans->isEmpty()) {
            return null;
        }

        // All of a trip's plans are generated from the same itinerary total, so they always
        // share one currency — safe to read off whichever plan happens to be first.
        $currency = $plans->first()->currency;
        $collected = round($plans->sum(fn (PaymentPlan $plan) => $this->collectedViaWeWire($plan)), 2);
        $disbursed = (float) WeWireDisbursement::where('source_trip_id', $trip->trip_id)
            ->whereIn('status', [DisbursementStatus::PENDING, DisbursementStatus::SUCCESSFUL])
            ->sum('amount');

        return ['currency' => $currency, 'collected' => $collected, 'disbursed' => $disbursed, 'held' => round($collected - $disbursed, 2)];
    }

    // Sum of every *completed* WeWire transaction collected against a plan's installments —
    // the gross amount available to pay out, before subtracting anything already disbursed.
    // Assumes installments.installmentPayments.transaction is eager-loaded.
    private function collectedViaWeWire(PaymentPlan $plan): float
    {
        return round($plan->installments->sum(function (Installment $installment) {
            return $installment->installmentPayments
                ->filter(fn(InstallmentPayment $ip) => $ip->transaction?->status === TransactionStatus::COMPLETED)
                ->sum(fn(InstallmentPayment $ip) => (float) $ip->transaction->amount);
        }), 2);
    }
}
