<?php

namespace App\Http\Controllers;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Helpers\UserHelper;
use App\Models\Installment;
use App\Models\PaymentPlan;
use App\Models\Trip;
use App\Services\IdGeneratorService;
use App\Services\ReferenceCodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Creates and manages a trip's WeWire installment plan — either a single lump-sum
 * "installment" or a fixed set the agency defines up front. This is what the public
 * /pay/:reference collection page (WeWirePaymentController::lookupPublic) resolves.
 *
 * Routes: /api/trips/{trip}/payment-plan, /api/payment-plans/{plan}/reference (authenticated).
 */
class PaymentPlanController extends Controller
{
    // POST /api/trips/{trip}/payment-plan — one plan per trip (unique trip_id). Installment
    // amounts must sum exactly to total_amount (a single lump-sum "installment" is just a
    // plan with one row). Auto-generates the reference code via ReferenceCodeGenerator.
    public function store(Request $request, Trip $trip): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($trip->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($trip->paymentPlan()->exists()) {
            return response()->json(['message' => 'This trip already has a payment plan.'], 422);
        }

        $validated = $request->validate([
            'total_amount' => 'required|numeric|min:1',
            'currency' => 'required|string|max:3',
            'installments' => 'required|array|min:1',
            'installments.*.amount' => 'required|numeric|min:0.01',
            'installments.*.due_date' => 'nullable|date',
        ]);

        $installmentsSum = round(array_sum(array_column($validated['installments'], 'amount')), 2);
        if (abs($installmentsSum - round((float) $validated['total_amount'], 2)) > 0.01) {
            return response()->json(['message' => 'Installment amounts must sum to the total amount.'], 422);
        }

        $plan = DB::transaction(function () use ($validated, $trip, $request) {
            $plan = PaymentPlan::create([
                'id' => IdGeneratorService::generateId('PLN'),
                'trip_id' => $trip->trip_id,
                'payment_reference' => ReferenceCodeGenerator::generate(),
                'total_amount' => $validated['total_amount'],
                'currency' => $validated['currency'],
                'status' => PaymentPlanStatus::ACTIVE->value,
                'created_by' => $request->user()->user_id,
            ]);

            foreach (array_values($validated['installments']) as $index => $installment) {
                Installment::create([
                    'id' => IdGeneratorService::generateId('INS'),
                    'payment_plan_id' => $plan->id,
                    'sequence' => $index + 1,
                    'amount' => $installment['amount'],
                    'currency' => $validated['currency'],
                    'due_date' => $installment['due_date'] ?? null,
                    'status' => InstallmentStatus::PENDING->value,
                ]);
            }

            return $plan;
        });

        return response()->json($plan->load('installments'), 201);
    }

    // GET /api/trips/{trip}/payment-plan
    public function show(Request $request, Trip $trip): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($trip->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $plan = $trip->paymentPlan()->with('installments')->first();
        if (!$plan) {
            return response()->json(['message' => 'No payment plan for this trip yet.'], 404);
        }

        return response()->json($plan);
    }

    // PATCH /api/payment-plans/{plan}/reference — lets agency staff override the
    // auto-generated reference code (e.g. to something easier to read out over a call).
    public function updateReference(Request $request, PaymentPlan $plan): JsonResponse
    {
        $company = UserHelper::user_company($request);
        if ($plan->trip->company_id !== $company->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'payment_reference' => 'required|string|size:8|regex:/^[A-Z]{5}[0-9]{3}$/|unique:payment_plans,payment_reference,' . $plan->id . ',id',
        ]);

        $plan->update($validated);

        return response()->json($plan);
    }
}
