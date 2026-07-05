<?php

namespace App\Helpers;

use App\Models\Call;
use Illuminate\Http\Request;

/**
 * Authorization helper for calls — mirrors ItineraryHelper's pattern for itineraries.
 */
class CallHelper
{
    /**
     * Whether the authenticated user's active company owns the trip this call belongs to.
     *
     * @return bool
     */
    public static function is_user_company_call(Request $request, Call $call): bool
    {
        $company = UserHelper::user_company($request);
        return $call->trip->company_id === $company->company_id;
    }
}
