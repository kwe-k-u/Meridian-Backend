<?php

namespace App\Helpers;

use App\Models\Trip;
use Illuminate\Http\Request;

/**
 * Whether the authenticated user's active company owns the given trip.
 *
 * Not currently called anywhere — TripController inlines this same check
 * (`$trip->company_id !== UserHelper::user_company($request)->company_id`) directly
 * in each of its methods instead of using this helper. Kept here for reuse if that
 * gets refactored, or safe to remove if it stays unused.
 */
class TripHelper
{
    /**
     * @return bool
     */
    public static function is_user_company_trip(Request $request, Trip $trip): bool
    {
        return $request->user()->active_company->company_id == $trip->company_id;
    }
}
