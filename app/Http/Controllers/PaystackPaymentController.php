<?php

namespace App\Http\Controllers;

use App\Enums\SubscriptionStatus;
use App\Enums\TransactionStatus;
use App\Helpers\UserHelper;
use App\Models\CompanySubscription;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionTier;
use App\Models\Transaction;
use App\Services\IdGeneratorService;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hosted-checkout card/bank payments via Paystack (https://paystack.com/docs/), used for tour
 * operator subscription payments (trip payments go through WeWire — see
 * WeWirePaymentController).
 *
 * A subscription can't be marked active before its payment is confirmed, so
 * initiateSubscriptionPayment() creates the CompanySubscription as `pending` alongside the
 * pending Transaction/SubscriptionPayment, and syncStatusFromPaystack() is what flips both to
 * their final state once Paystack confirms the payment.
 *
 * Routes: /api/payments/paystack/subscription, /{transaction}/status (authenticated),
 * /api/payments/paystack/webhook (public — see routes/api.php).
 */
class PaystackPaymentController extends Controller
{
    // POST /api/payments/paystack/subscription — Creates a pending CompanySubscription +
    // Transaction + SubscriptionPayment for the given tier, then asks Paystack for a hosted
    // checkout link the customer can pay through.
    public function initiateSubscriptionPayment(Request $request, PaystackService $paystack): JsonResponse
    {
        $company = UserHelper::user_company($request);

        $validated = $request->validate([
            'tier_id' => 'required|string|exists:subscription_tiers,tier_id',
        ]);

        $tier = SubscriptionTier::find($validated['tier_id']);

        [$transaction, $subscription] = DB::transaction(function () use ($tier, $company, $request) {
            $subscription = CompanySubscription::create([
                'subscription_id' => IdGeneratorService::generateId('SUB'),
                'company_id' => $company->company_id,
                'tier_id' => $tier->tier_id,
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => SubscriptionStatus::PENDING->value,
            ]);

            $transactionId = IdGeneratorService::generateId('TXN');

            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'amount' => $tier->price_quarterly,
                'currency' => 'GHS',
                'payment_method' => 'paystack',
                'transaction_reference' => $transactionId,
                'status' => TransactionStatus::PENDING->value,
            ]);

            SubscriptionPayment::create([
                'transaction_id' => $transactionId,
                'subscription_id' => $subscription->subscription_id,
                'company_id' => $company->company_id,
                'initiated_by' => $request->user()->user_id,
            ]);

            return [$transaction, $subscription];
        });

        $email = $request->user()->email ?? 'payments@meridian.app';
        $callback = rtrim(config('services.paystack.frontend_url'), '/')
            . '/app/payments/callback?ref=' . $transaction->transaction_id . '&provider=paystack';

        $result = $paystack->initializeTransaction(
            $transaction->transaction_id,
            (float) $transaction->amount,
            $transaction->currency,
            $email,
            $callback,
        );

        $authorizationUrl = $result['data']['authorization_url'] ?? null;

        if (($result['status'] ?? null) !== true || !$authorizationUrl) {
            Log::warning('Paystack transaction initialization failed', [
                'transaction_id' => $transaction->transaction_id,
                'response' => $result,
            ]);
            $subscription->delete();
            $transaction->subscriptionPayment()->delete();
            $transaction->delete();
            return response()->json(['message' => $result['message'] ?? 'Could not start the Paystack payment.'], 502);
        }

        return response()->json([
            'transaction_id' => $transaction->transaction_id,
            'authorization_url' => $authorizationUrl,
        ]);
    }

    // POST /api/payments/paystack/webhook — Public callback Paystack POSTs to on payment
    // completion. Not behind auth:sanctum (Paystack has no way to send our bearer tokens), so
    // the request is authenticated a different way: Paystack signs the raw body with our
    // secret key (x-paystack-signature, HMAC-SHA512) and we verify that before trusting
    // anything in it. Even after that check passes, the payload's own status is never trusted
    // directly — we still independently ask Paystack (server-to-server) what happened. Always
    // returns 200 unless the signature/reference is invalid, since Paystack retries on non-2xx.
    public function webhook(Request $request, PaystackService $paystack): JsonResponse
    {
        $signature = $request->header('x-paystack-signature');
        $secretKey = config('services.paystack.secret_key');

        if (!$signature || !$secretKey || !hash_equals(hash_hmac('sha512', $request->getContent(), $secretKey), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $reference = $request->input('data.reference');
        if (!$reference) {
            return response()->json(['message' => 'Missing reference.'], 400);
        }

        $transaction = Transaction::find($reference);
        if (!$transaction) {
            return response()->json(['message' => 'Unknown transaction.'], 404);
        }

        $this->syncStatusFromPaystack($transaction, $paystack);

        return response()->json(['message' => 'ok']);
    }

    // GET /api/payments/paystack/{transaction}/status — Polled by the frontend's post-checkout
    // return page (PaymentCallback.tsx) since the webhook can't reach a plain localhost backend
    // in development.
    public function status(Request $request, Transaction $transaction, PaystackService $paystack): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($transaction->subscriptionPayment?->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($transaction->status === TransactionStatus::PENDING) {
            $this->syncStatusFromPaystack($transaction, $paystack);
        }

        return response()->json($transaction->fresh()->load('subscriptionPayment'));
    }

    // Looks up the real status from Paystack and updates our Transaction (and the linked
    // CompanySubscription) to match. A no-op once the transaction has already settled, so
    // repeated webhook deliveries / status polls are safe to call this again and again.
    private function syncStatusFromPaystack(Transaction $transaction, PaystackService $paystack): void
    {
        if ($transaction->status !== TransactionStatus::PENDING) {
            return;
        }

        $result = $paystack->verifyTransaction($transaction->transaction_id);
        $status = $result['data']['status'] ?? null;

        if ($status === 'success') {
            $transaction->update(['status' => TransactionStatus::COMPLETED->value, 'paid_at' => now()]);
            $subscription = $transaction->subscriptionPayment?->subscription;
            $subscription?->update(['status' => SubscriptionStatus::ACTIVE->value]);
        } elseif (in_array($status, ['failed', 'abandoned'], true)) {
            $transaction->update(['status' => TransactionStatus::FAILED->value]);
            $subscription = $transaction->subscriptionPayment?->subscription;
            $subscription?->update(['status' => SubscriptionStatus::CANCELLED->value]);
        }
    }
}
