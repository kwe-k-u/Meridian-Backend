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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        return response()->json([
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
        ]);
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

    // Creates the WeWireDisbursement audit row *before* calling WeWire (so a crash mid-call
    // still leaves a PENDING row rather than nothing), then calls initiatePayout(). If WeWire
    // never even accepts the request (no `id` in the response), the row is marked
    // INITIATION_FAILED immediately — there's no wewire_transaction_id for a later webhook to
    // ever update, so this is the only chance to record the failure reason.
    //
    // $source is either ['source_inbound_id' => ...] (automatic, one inbound payment) or
    // ['source_trip_id' => ...] (manual, sweeps up everything collected on a trip — see
    // payoutTrip()). Exactly one key is expected; the other column stays null.
    private function initiateDisbursement(WeWireVirtualAccount $account, WeWireBeneficiary $beneficiary, float $amount, string $currency, array $source): WeWireDisbursement
    {
        $disbursement = WeWireDisbursement::create(array_merge([
            'id' => IdGeneratorService::generateId('WWD'),
            'virtual_account_id' => $account->id,
            'beneficiary_id' => $beneficiary->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => DisbursementStatus::PENDING->value,
            'initiated_at' => now(),
        ], $source));

        try {
            $wewire = app(WeWireService::class);
            $result = $wewire->initiatePayout([
                'from' => $currency,
                'to' => $beneficiary->currency,
                'amount' => $amount,
                'beneficiaryAccountId' => $beneficiary->wewire_beneficiary_id,
                'reference' => $disbursement->id,
            ]);

            if (isset($result['id'])) {
                $disbursement->update([
                    'wewire_transaction_id' => $result['id'],
                    'status' => isset($result['status']) ? strtolower($result['status']) : DisbursementStatus::PENDING->value,
                    'fee' => $result['fee'] ?? null,
                ]);
            } else {
                Log::warning('WeWire disbursement initiation rejected', ['disbursement_id' => $disbursement->id, 'response' => $result]);
                $disbursement->update([
                    'status' => DisbursementStatus::INITIATION_FAILED->value,
                    'failure_reason' => $result['message'] ?? 'WeWire did not accept the payout request.',
                ]);
            }
        } catch (Exception $e) {
            Log::error('WeWire disbursement initiation threw', ['disbursement_id' => $disbursement->id, 'error' => $e->getMessage()]);
            $disbursement->update([
                'status' => DisbursementStatus::INITIATION_FAILED->value,
                'failure_reason' => $e->getMessage(),
            ]);
        }

        return $disbursement->fresh();
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

        $retry = $this->initiateDisbursement(
            $disbursement->virtualAccount,
            $disbursement->beneficiary,
            (float) $disbursement->amount,
            $disbursement->currency,
            $disbursement->source_trip_id
                ? ['source_trip_id' => $disbursement->source_trip_id]
                : ['source_inbound_id' => $disbursement->source_inbound_id],
        );

        return response()->json($retry, 201);
    }

    // GET /api/wewire/trip-balances — for the dashboard's "Pay out agency" panel: every trip
    // with a payment plan that has collected money via WeWire and hasn't been fully paid out
    // yet. A trip's "held balance" is bookkeeping on our side (WeWire itself doesn't earmark
    // wallet funds by trip) — completed WeWire installment payments, minus whatever's already
    // been swept out for that trip via a PENDING or SUCCESSFUL WeWireDisbursement.
    public function tripBalances(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);

        $plans = PaymentPlan::with(['trip', 'installments.installmentPayments.transaction'])
            ->whereHas('trip', fn($q) => $q->where('company_id', $company->company_id))
            ->get();

        $disbursedByTrip = WeWireDisbursement::whereIn('source_trip_id', $plans->pluck('trip_id'))
            ->whereIn('status', [DisbursementStatus::PENDING, DisbursementStatus::SUCCESSFUL])
            ->get()
            ->groupBy('source_trip_id')
            ->map(fn($rows) => $rows->sum('amount'));

        $balances = $plans->map(function (PaymentPlan $plan) use ($disbursedByTrip, $company) {
            $collected = $this->collectedViaWeWire($plan);
            $disbursed = (float) ($disbursedByTrip[$plan->trip_id] ?? 0);
            $held = round($collected - $disbursed, 2);

            $account = WeWireVirtualAccount::where('company_id', $company->company_id)
                ->where('currency', $plan->currency)
                ->where('status', VirtualAccountStatus::ACTIVE->value)
                ->first();
            $hasBeneficiary = $account && $account->beneficiary_account_id;

            return [
                'trip_id' => $plan->trip_id,
                'trip_name' => $plan->trip->trip_name,
                'currency' => $plan->currency,
                'collected' => $collected,
                'held_balance' => $held,
                'can_payout' => $held > 0.01 && $hasBeneficiary,
            ];
        })->filter(fn($b) => $b['held_balance'] > 0.01)->values();

        return response()->json($balances);
    }

    // POST /api/trips/{trip}/payout — manually pays an agency out for everything currently
    // held for one trip (see tripBalances() for how "held" is computed), to the company's
    // beneficiary account in that trip's currency.
    public function payoutTrip(Request $request, Trip $trip): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($trip->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $plan = $trip->paymentPlan()->with('installments.installmentPayments.transaction')->first();
        if (!$plan) {
            return response()->json(['message' => 'This trip has no payment plan / no WeWire collections yet.'], 422);
        }

        $collected = $this->collectedViaWeWire($plan);
        $alreadyDisbursed = (float) WeWireDisbursement::where('source_trip_id', $trip->trip_id)
            ->whereIn('status', [DisbursementStatus::PENDING, DisbursementStatus::SUCCESSFUL])
            ->sum('amount');
        $held = round($collected - $alreadyDisbursed, 2);

        if ($held <= 0.01) {
            return response()->json(['message' => 'Nothing is currently held for this trip.'], 422);
        }

        $account = WeWireVirtualAccount::with('beneficiary')
            ->where('company_id', $company->company_id)
            ->where('currency', $plan->currency)
            ->where('status', VirtualAccountStatus::ACTIVE->value)
            ->first();
        if (!$account || !$account->beneficiary) {
            return response()->json(['message' => "Add a {$plan->currency} beneficiary account in Settings before paying out this trip."], 422);
        }

        $disbursement = $this->initiateDisbursement($account, $account->beneficiary, $held, $plan->currency, ['source_trip_id' => $trip->trip_id]);

        return response()->json($disbursement, 201);
    }

    // Sum of every *completed* WeWire transaction collected against a plan's installments —
    // the gross amount available to pay the agency out for, before subtracting anything
    // already disbursed. Assumes installments.installmentPayments.transaction is eager-loaded.
    private function collectedViaWeWire(PaymentPlan $plan): float
    {
        return round($plan->installments->sum(function (Installment $installment) {
            return $installment->installmentPayments
                ->filter(fn(InstallmentPayment $ip) => $ip->transaction?->status === TransactionStatus::COMPLETED)
                ->sum(fn(InstallmentPayment $ip) => (float) $ip->transaction->amount);
        }), 2);
    }
}
