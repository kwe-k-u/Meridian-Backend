<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles CRUD operations for subscription tier plans (pricing, features).
 *
 * Routes: only GET /api/subscription-tiers and GET /api/subscription-tiers/{id} are
 * registered in routes/api.php — the Pricing page reads the plan catalog through these.
 * store/update/destroy below are intentionally NOT routed: this app has no platform-admin
 * role/permission model, so exposing "create/edit/delete a pricing plan" to every
 * authenticated user would let any company invent or change plans for the whole platform.
 */
class SubscriptionTierController extends Controller
{
    // GET /api/subscription-tiers — Returns paginated list of subscription tiers.
    public function index(): JsonResponse
    {
        return response()->json(SubscriptionTier::paginate(15));
    }

    // Not routed — see class docblock.
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

    // Not routed — see class docblock.
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

    // Not routed — see class docblock.
    public function destroy(SubscriptionTier $subscriptionTier): JsonResponse
    {
        $subscriptionTier->delete();
        return response()->json(null, 204);
    }
}