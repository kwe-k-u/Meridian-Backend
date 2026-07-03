<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Helpers\TransactionHelper;
use App\Helpers\UserHelper;
use App\Models\Transaction;
use App\Models\SubscriptionPayment;
use App\Models\TripPayment;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

/**
 * Manages financial transactions, subscription payments, and trip payments.
 *
 * Routes: /api/transactions, /api/transactions/subscription-payment, /api/transactions/trip-payment
 */
class TransactionController extends Controller
{
    // GET /api/transactions — Returns paginated list of transactions scoped to the user's companies.
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);

        return response()->json(
            Transaction::with(['tripPayment.trip', 'subscriptionPayment'])
                ->whereIn('transaction_id', function ($q) use ($company) {
                    $q->select('transaction_id')
                        ->from('trip_payments')
                        ->where('trip_id', function ($q2) use ($company) {
                            $q2->select('trip_id')->from('trips')->whereIn('company_id', $company->company_id);
                        });
                })
                ->orWhereIn('transaction_id', function ($q) use ($company) {
                    $q->select('transaction_id')
                        ->from('subscription_payments')
                        ->where('company_id', $company->company_id);
                })
                ->orderBy('created_at', 'desc')
                ->paginate(15)
        );
    }

    // GET /api/transactions/{transaction} — Returns a single transaction with payment details (company-scoped).
    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $accessible = TransactionHelper::is_transaction_accessible($company, $transaction);
        if (!$accessible) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($transaction->load(['subscriptionPayment', 'tripPayment.trip']));
    }

    // POST /api/transactions/subscription-payment — Records a subscription payment transaction in a DB transaction.
    public function recordSubscriptionPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|string|exists:companies,company_id',
            'subscription_id' => 'required|string|exists:company_subscriptions,subscription_id',
            'initiated_by' => 'nullable|string|exists:users,user_id',
            'amount' => 'required|integer|min:0',
            'currency' => 'nullable|string|max:3',
            'payment_method' => 'nullable|string|max:50',
            'transaction_reference' => 'nullable|string|max:255',
            'status' => ['nullable', new Enum(TransactionStatus::class)],
        ]);

        return DB::transaction(function () use ($validated) {
            $transactionId = IdGeneratorService::generateId('TXN');
            $status = $validated['status'] ?? 'pending';

            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'amount' => $validated['amount'],
                'currency' => $validated['currency'] ?? 'GHS',
                'payment_method' => $validated['payment_method'] ?? null,
                'transaction_reference' => $validated['transaction_reference'] ?? null,
                'status' => $status,
                'paid_at' => $status === 'completed' ? now() : null,
            ]);

            SubscriptionPayment::create([
                'transaction_id' => $transactionId,
                'subscription_id' => $validated['subscription_id'],
                'company_id' => $validated['company_id'],
                'initiated_by' => $validated['initiated_by'] ?? null,
            ]);

            return response()->json($transaction->load('subscriptionPayment'), 201);
        });
    }

    // POST /api/transactions/trip-payment — Records a trip payment transaction in a DB transaction.
    public function recordTripPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'trip_id' => 'required|string|exists:trips,trip_id',
            'amount' => 'required|integer|min:0',
            'currency' => 'nullable|string|max:3',
            'payment_method' => 'nullable|string|max:50',
            'transaction_reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'status' => ['nullable', new Enum(TransactionStatus::class)],
        ]);

        return DB::transaction(function () use ($validated) {
            $transactionId = IdGeneratorService::generateId('TXN');
            $status = $validated['status'] ?? 'pending';

            $transaction = Transaction::create([
                'transaction_id' => $transactionId,
                'amount' => $validated['amount'],
                'currency' => $validated['currency'] ?? 'GHS',
                'payment_method' => $validated['payment_method'] ?? null,
                'transaction_reference' => $validated['transaction_reference'] ?? null,
                'status' => $status,
                'paid_at' => $status === 'completed' ? now() : null,
            ]);

            TripPayment::create([
                'transaction_id' => $transactionId,
                'trip_id' => $validated['trip_id'],
                'notes' => $validated['notes'] ?? null,
            ]);

            return response()->json($transaction->load('tripPayment'), 201);
        });
    }

    // PUT /api/transactions/{transaction}/status — Updates a transaction's status (company-scoped).
    public function updateStatus(Request $request, Transaction $transaction): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $accessible = TransactionHelper::is_transaction_accessible($company, $transaction);
        if (!$accessible) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', new Enum(TransactionStatus::class)],
        ]);

        $transaction->update([
            'status' => $validated['status'],
            'paid_at' => $validated['status'] === 'completed' ? now() : $transaction->paid_at,
        ]);

        return response()->json($transaction);
    }
}
