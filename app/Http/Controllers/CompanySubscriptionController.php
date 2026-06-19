<?php

namespace App\Http\Controllers;

use App\Models\CompanySubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use App\Enums\SubscriptionStatus;

class CompanySubscriptionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(CompanySubscription::with(['company', 'tier'])->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subscription_id' => 'required|string|max:20|unique:company_subscriptions,subscription_id',
            'company_id' => 'required|string|exists:companies,company_id',
            'tier_id' => 'required|string|exists:subscription_tiers,tier_id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'status' => ['nullable', new Enum(SubscriptionStatus::class)],
        ]);

        $subscription = CompanySubscription::create($validated);

        return response()->json($subscription, 201);
    }

    public function show(CompanySubscription $companySubscription): JsonResponse
    {
        return response()->json($companySubscription->load(['company', 'tier']));
    }

    public function update(Request $request, CompanySubscription $companySubscription): JsonResponse
    {
        $validated = $request->validate([
            'end_date' => 'sometimes|required|date',
            'status' => ['sometimes', 'required', new Enum(SubscriptionStatus::class)],
        ]);

        $companySubscription->update($validated);

        return response()->json($companySubscription);
    }

    public function destroy(CompanySubscription $companySubscription): JsonResponse
    {
        $companySubscription->delete();
        return response()->json(null, 204);
    }
}