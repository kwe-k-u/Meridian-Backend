<?php

namespace App\Http\Controllers;

use App\Helpers\UserHelper;
use App\Models\CompanySubscription;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use App\Enums\SubscriptionStatus;

/**
 * Manages company subscription assignments to subscription tiers.
 *
 * Routes: only index/show/store are registered in routes/api.php, all scoped to the
 * caller's own active company (a regular user has no reason to see or create subscriptions
 * for a different company). update/destroy are intentionally NOT routed — changing an
 * existing subscription's dates/status is treated as a billing-admin operation this app
 * doesn't have a role model for yet (same reasoning as SubscriptionTierController).
 */
class CompanySubscriptionController extends Controller
{
    // GET /api/company-subscriptions — Returns the caller's own company's subscription
    // history (with tier details), most recent first.
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);

        return response()->json(
            CompanySubscription::with(['company', 'tier'])
                ->where('company_id', $company->company_id)
                ->orderBy('start_date', 'desc')
                ->paginate(15)
        );
    }

    // POST /api/company-subscriptions — Subscribes the caller's own company to a tier.
    // The pairing with a payment (see TransactionController::recordSubscriptionPayment) is
    // left to the caller to do as a second request — this only creates the subscription record.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tier_id' => 'required|string|exists:subscription_tiers,tier_id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'status' => ['nullable', new Enum(SubscriptionStatus::class)],
        ]);

        $company = UserHelper::user_company($request);
        $validated['subscription_id'] = IdGeneratorService::generateId('SUB');
        $validated['company_id'] = $company->company_id;

        $subscription = CompanySubscription::create($validated);

        return response()->json($subscription->load(['company', 'tier']), 201);
    }

    // GET /api/company-subscriptions/{companySubscription} — Returns a single subscription
    // with company and tier (only reachable for the caller's own company).
    public function show(Request $request, CompanySubscription $companySubscription): JsonResponse
    {
        if ($companySubscription->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($companySubscription->load(['company', 'tier']));
    }

    // Not routed — see class docblock.
    public function update(Request $request, CompanySubscription $companySubscription): JsonResponse
    {
        $validated = $request->validate([
            'end_date' => 'sometimes|required|date',
            'status' => ['sometimes', 'required', new Enum(SubscriptionStatus::class)],
        ]);

        $companySubscription->update($validated);

        return response()->json($companySubscription);
    }

    // Not routed — see class docblock.
    public function destroy(CompanySubscription $companySubscription): JsonResponse
    {
        $companySubscription->delete();
        return response()->json(null, 204);
    }
}
