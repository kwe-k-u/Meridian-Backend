<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles CRUD operations for subscription tier plans (pricing, features).
 *
 * Routes: /api/subscription-tiers (resourceful)
 */
class SubscriptionTierController extends Controller
{
    // GET /api/subscription-tiers — Returns paginated list of subscription tiers.
    public function index(): JsonResponse
    {
        return response()->json(SubscriptionTier::paginate(15));
    }

    // POST /api/subscription-tiers — Creates a new subscription tier.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tier_id' => 'required|string|max:20|unique:subscription_tiers,tier_id',
            'name' => 'required|string|max:50',
            'price_quarterly' => 'required|integer|min:0',
            'features' => 'nullable|array',
            'status' => 'nullable|boolean',
        ]);

        $tier = SubscriptionTier::create($validated);

        return response()->json($tier, 201);
    }

    // GET /api/subscription-tiers/{subscriptionTier} — Returns a single subscription tier.
    public function show(SubscriptionTier $subscriptionTier): JsonResponse
    {
        return response()->json($subscriptionTier);
    }

    // PUT/PATCH /api/subscription-tiers/{subscriptionTier} — Updates a subscription tier's name, price, features, or status.
    public function update(Request $request, SubscriptionTier $subscriptionTier): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:50',
            'price_quarterly' => 'sometimes|required|integer|min:0',
            'features' => 'nullable|array',
            'status' => 'sometimes|required|boolean',
        ]);

        $subscriptionTier->update($validated);

        return response()->json($subscriptionTier);
    }

    // DELETE /api/subscription-tiers/{subscriptionTier} — Deletes a subscription tier.
    public function destroy(SubscriptionTier $subscriptionTier): JsonResponse
    {
        $subscriptionTier->delete();
        return response()->json(null, 204);
    }
}