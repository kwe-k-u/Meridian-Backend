<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionTierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(SubscriptionTier::paginate(15));
    }

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

    public function show(SubscriptionTier $subscriptionTier): JsonResponse
    {
        return response()->json($subscriptionTier);
    }

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

    public function destroy(SubscriptionTier $subscriptionTier): JsonResponse
    {
        $subscriptionTier->delete();
        return response()->json(null, 204);
    }
}