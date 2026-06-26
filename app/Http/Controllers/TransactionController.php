<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\SubscriptionPayment;
use App\Models\TripPayment;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Enum;

class TransactionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Transaction::paginate(15));
    }

    public function show(Transaction $transaction): JsonResponse
    {
        return response()->json($transaction->load(['subscriptionPayment', 'tripPayment']));
    }

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

    public function updateStatus(Request $request, Transaction $transaction): JsonResponse
    {
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
