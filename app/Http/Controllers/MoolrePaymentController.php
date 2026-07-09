<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Helpers\ItineraryHelper;
use App\Helpers\UserHelper;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPayment;
use App\Models\Transaction;
use App\Models\Trip;
use App\Models\TripPayment;
use App\Services\IdGeneratorService;
use App\Services\MoolreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hosted-checkout mobile-money payments via Moolre (https://docs.moolre.com/).
 *
 * A Moolre payment is just an ordinary Transaction (see App\Models\Transaction) that starts
 * out `pending` and a TripPayment/SubscriptionPayment row linking it to what it's for — the
 * same shape TransactionController::recordTripPayment/recordSubscriptionPayment already use
 * for manually-recorded payments. What's different here is *when* it becomes `completed`:
 * instead of the caller telling us directly, we ask Moolre (see syncStatusFromMoolre()).
 *
 * Routes: /api/payments/moolre/trip, /subscription, /{transaction}/status (authenticated),
 * /api/payments/moolre/webhook (public), and /api/public/payments/moolre/trip,
 * /{transaction}/status (public — traveler-initiated trip payments, no Meridian account
 * required; see routes/api.php).
 */
class MoolrePaymentController extends Controller
{
    // POST /api/payments/moolre/trip — Creates a pending trip payment, then asks Moolre for a
    // hosted checkout link the customer can pay through.
    public function initiateTripPayment(Request $request, MoolreService $moolre): JsonResponse
    {
        $company = UserHelper::user_company($request);

        $validated = $request->validate([
            'trip_id' => 'required|string|exists:trips,trip_id',
            'amount' => 'required|numeric|min:1',
            'currency' => 'nullable|string|max:3',
            'notes' => 'nullable|string',
        ]);

        $trip = Trip::find($validated['trip_id']);
        if (!$trip || $trip->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $transaction = DB::transaction(function () use ($validated) {
            $transactionId = IdGeneratorService::generateId('TXN');

            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'amount' => $validated['amount'],
                'currency' => $validated['currency'] ?? 'GHS',
                'payment_method' => 'moolre',
                'transaction_reference' => $transactionId,
                'status' => TransactionStatus::PENDING->value,
            ]);

            TripPayment::create([
                'transaction_id' => $transactionId,
                'trip_id' => $validated['trip_id'],
                'notes' => $validated['notes'] ?? null,
            ]);

            return $transaction;
        });

        $email = $request->user()->email ?? 'payments@meridian.app';
        $redirect = rtrim(config('services.moolre.frontend_url'), '/') . '/app/payments/callback?ref=' . $transaction->transaction_id;
        return $this->requestCheckoutLink($moolre, $transaction, $email, $redirect);
    }

    // POST /api/public/payments/moolre/trip — Public equivalent of initiateTripPayment(), used
    // by the traveler-facing TravelerView.tsx "Pay" flow — no Meridian account required, since
    // trip_id from the shareable link is the only thing identifying the payer. The amount is
    // re-validated against the trip's own outstanding balance server-side (never trusts
    // whatever the client computed), so a traveler can never overpay or pay a trip that's
    // already settled.
    public function initiatePublicTripPayment(Request $request, MoolreService $moolre): JsonResponse
    {
        $validated = $request->validate([
            'trip_id' => 'required|string|exists:trips,trip_id',
            'amount' => 'required|numeric|min:50',
        ]);

        $trip = Trip::with([
            'customers',
            'itineraries.itineraryDays.destinations',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'tripPayments.transaction',
        ])->find($validated['trip_id']);

        if (!$trip) {
            return response()->json(['message' => 'Trip not found.'], 404);
        }

        $outstanding = $this->calculateOutstanding($trip);

        if ($outstanding <= 0) {
            return response()->json(['message' => 'This trip has no outstanding balance.'], 422);
        }
        // Small epsilon to tolerate float rounding when the traveler chose "pay outstanding
        // balance in full" and the client-computed total drifted a cent from ours.
        if ($validated['amount'] > $outstanding + 0.01) {
            return response()->json(['message' => 'Amount exceeds the outstanding balance.'], 422);
        }

        $transaction = DB::transaction(function () use ($validated) {
            $transactionId = IdGeneratorService::generateId('TXN');

            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'amount' => $validated['amount'],
                'currency' => 'GHS',
                'payment_method' => 'moolre',
                'transaction_reference' => $transactionId,
                'status' => TransactionStatus::PENDING->value,
            ]);

            TripPayment::create([
                'transaction_id' => $transactionId,
                'trip_id' => $validated['trip_id'],
                'notes' => 'Traveler payment',
            ]);

            return $transaction;
        });

        $email = $trip->customers->first()?->email ?? 'payments@meridian.app';
        $redirect = rtrim(config('services.moolre.frontend_url'), '/') . '/travel/' . $trip->trip_id . '/payment-callback?ref=' . $transaction->transaction_id;
        return $this->requestCheckoutLink($moolre, $transaction, $email, $redirect);
    }

    // Outstanding balance for a trip: sum of every itinerary's total cost, minus whatever's
    // already been paid (completed transactions only). Mirrors TripController::buildCostsResponse()'s
    // summary.outstanding — kept separate since that method is private to TripController and
    // this is the one place a payment amount actually needs to be validated against it.
    // Assumes itineraries.*/tripPayments.transaction are already eager-loaded on $trip.
    private function calculateOutstanding(Trip $trip): float
    {
        $totalCost = $trip->itineraries->sum(fn($itin) => ItineraryHelper::calculateItineraryCost($itin)['total']);
        $totalPaid = (float) $trip->tripPayments
            ->filter(fn($tp) => $tp->transaction->status === TransactionStatus::COMPLETED)
            ->sum(fn($tp) => (float) $tp->transaction->amount);

        return round($totalCost - $totalPaid, 2);
    }

    // POST /api/payments/moolre/subscription — Same idea as initiateTripPayment(), but for a
    // company's own subscription (see CompanySubscriptionController::store for how the
    // subscription itself gets created before this is called).
    public function initiateSubscriptionPayment(Request $request, MoolreService $moolre): JsonResponse
    {
        $company = UserHelper::user_company($request);

        $validated = $request->validate([
            'subscription_id' => 'required|string|exists:company_subscriptions,subscription_id',
            'amount' => 'required|numeric|min:1',
            'currency' => 'nullable|string|max:3',
        ]);

        $subscription = CompanySubscription::find($validated['subscription_id']);
        if (!$subscription || $subscription->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $transaction = DB::transaction(function () use ($validated, $company, $request) {
            $transactionId = IdGeneratorService::generateId('TXN');

            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'amount' => $validated['amount'],
                'currency' => $validated['currency'] ?? 'GHS',
                'payment_method' => 'moolre',
                'transaction_reference' => $transactionId,
                'status' => TransactionStatus::PENDING->value,
            ]);

            SubscriptionPayment::create([
                'transaction_id' => $transactionId,
                'subscription_id' => $validated['subscription_id'],
                'company_id' => $company->company_id,
                'initiated_by' => $request->user()->user_id,
            ]);

            return $transaction;
        });

        $email = $request->user()->email ?? 'payments@meridian.app';
        $redirect = rtrim(config('services.moolre.frontend_url'), '/') . '/app/payments/callback?ref=' . $transaction->transaction_id;
        return $this->requestCheckoutLink($moolre, $transaction, $email, $redirect);
    }

    // Shared by every initiate* method above: asks Moolre for a checkout link for a
    // just-created pending Transaction. If Moolre rejects the request, the transaction (and
    // its trip/subscription payment row) is deleted rather than left stuck `pending` forever
    // with nothing pointing back at it.
    private function requestCheckoutLink(MoolreService $moolre, Transaction $transaction, string $email, string $redirect): JsonResponse
    {
        $result = $moolre->generatePaymentLink(
            $transaction->transaction_id,
            (float) $transaction->amount,
            $transaction->currency,
            $email,
            null,
            $redirect,
        );

        $authorizationUrl = $result['data']['authorization_url'] ?? null;

        if (($result['status'] ?? null) != 1 || !$authorizationUrl) {
            Log::warning('Moolre payment link generation failed', [
                'transaction_id' => $transaction->transaction_id,
                'response' => $result,
            ]);
            $transaction->tripPayment()->delete();
            $transaction->subscriptionPayment()->delete();
            $transaction->delete();
            return response()->json(['message' => $result['message'] ?? 'Could not start the Moolre payment.'], 502);
        }

        return response()->json([
            'transaction_id' => $transaction->transaction_id,
            'authorization_url' => $authorizationUrl,
        ]);
    }

    // POST /api/payments/moolre/webhook — Public callback Moolre POSTs to on payment
    // completion. Not behind auth:sanctum (Moolre has no way to send our bearer tokens) and
    // reachable by anyone who knows the URL, so it deliberately never trusts the inbound
    // payload's claimed status — it only uses `externalref` to find the transaction, then
    // independently asks Moolre (server-to-server, with our own API keys) what actually
    // happened before marking anything completed. Always returns 200 unless the reference is
    // missing/unknown, since Moolre likely retries on non-2xx.
    public function webhook(Request $request, MoolreService $moolre): JsonResponse
    {
        $externalRef = $request->input('data.externalref') ?? $request->input('externalref');
        if (!$externalRef) {
            return response()->json(['message' => 'Missing externalref.'], 400);
        }

        $transaction = Transaction::find($externalRef);
        if (!$transaction) {
            return response()->json(['message' => 'Unknown transaction.'], 404);
        }

        $this->syncStatusFromMoolre($transaction, $moolre);

        return response()->json(['message' => 'ok']);
    }

    // GET /api/payments/moolre/{transaction}/status — Polled by the frontend's post-checkout
    // return page. This (not the webhook) is the reliable confirmation path in local
    // development, since Moolre's webhook can only reach an internet-facing callback URL —
    // this endpoint instead reaches out to Moolre, so it works over plain localhost.
    public function status(Request $request, Transaction $transaction, MoolreService $moolre): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $accessible = $transaction->tripPayment?->trip?->company_id === $company->company_id
            || $transaction->subscriptionPayment?->company_id === $company->company_id;
        if (!$accessible) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($transaction->status === TransactionStatus::PENDING) {
            $this->syncStatusFromMoolre($transaction, $moolre);
        }

        return response()->json($transaction->fresh()->load(['tripPayment.trip', 'subscriptionPayment']));
    }

    // GET /api/public/payments/moolre/{transaction}/status — Public equivalent of status(),
    // polled by the traveler-facing payment callback page after a Moolre checkout. Scoped to
    // trip payments only (never subscription payments) — a transaction_id is only ever handed
    // to the traveler who just initiated that specific payment, the same trust model as the
    // public trip endpoints above.
    public function publicStatus(Transaction $transaction, MoolreService $moolre): JsonResponse
    {
        if (!$transaction->tripPayment) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($transaction->status === TransactionStatus::PENDING) {
            $this->syncStatusFromMoolre($transaction, $moolre);
        }

        return response()->json($transaction->fresh()->load('tripPayment.trip'));
    }

    // Looks up the real status from Moolre and updates our Transaction to match. A no-op once
    // the transaction has already settled, so repeated webhook deliveries / status polls are
    // safe to call this again and again.
    private function syncStatusFromMoolre(Transaction $transaction, MoolreService $moolre): void
    {
        if ($transaction->status !== TransactionStatus::PENDING) {
            return;
        }

        $result = $moolre->checkPaymentStatus($transaction->transaction_id);
        $txStatus = $result['data']['txstatus'] ?? null;

        if ($txStatus == 1) {
            $transaction->update(['status' => TransactionStatus::COMPLETED->value, 'paid_at' => now()]);
        } elseif (($result['status'] ?? null) != 1 && !empty($result['code'])) {
            // An explicit non-success response (not just a transient HTTP hiccup) — treat as failed.
            $transaction->update(['status' => TransactionStatus::FAILED->value]);
        }
    }
}
