<?php

namespace App\Http\Controllers;

use App\Models\CompanySubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use App\Enums\SubscriptionStatus;

/**
 * Manages company subscription assignments to subscription tiers.
 *
 * Routes: /api/company-subscriptions (resourceful)
 */
class CompanySubscriptionController extends Controller
{
    // GET /api/company-subscriptions — Returns paginated list of subscriptions with company and tier.
    public function index(): JsonResponse
    {
        return response()->json(CompanySubscription::with(['company', 'tier'])->paginate(15));
    }

    // POST /api/company-subscriptions — Creates a new company subscription.
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

    // GET /api/company-subscriptions/{companySubscription} — Returns a single subscription with company and tier.
    public function show(CompanySubscription $companySubscription): JsonResponse
    {
        return response()->json($companySubscription->load(['company', 'tier']));
    }

    // PUT/PATCH /api/company-subscriptions/{companySubscription} — Updates end date or status.
    public function update(Request $request, CompanySubscription $companySubscription): JsonResponse
    {
        $validated = $request->validate([
            'end_date' => 'sometimes|required|date',
            'status' => ['sometimes', 'required', new Enum(SubscriptionStatus::class)],
        ]);

        $companySubscription->update($validated);

        return response()->json($companySubscription);
    }

    // DELETE /api/company-subscriptions/{companySubscription} — Deletes a company subscription.
    public function destroy(CompanySubscription $companySubscription): JsonResponse
    {
        $companySubscription->delete();
        return response()->json(null, 204);
    }
}