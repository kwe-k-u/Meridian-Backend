<?php

namespace App\Helpers;

use App\Models\Trip;
use Illuminate\Http\Request;

class TripHelper
{
    /**
     * Get user company
     *
     * @return bool
     */
    public static function is_user_company_trip(Request $request, Trip $trip): bool
    {
        return $request->user()->active_company->company_id == $trip->company_id;
    }
}
