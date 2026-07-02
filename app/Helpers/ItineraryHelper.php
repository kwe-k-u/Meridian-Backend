<?php

namespace App\Helpers;

use App\Models\Company;
use App\Models\Itinerary;
use Illuminate\Http\Request;

class ItineraryHelper
{
    /**
     * Get user company
     *
     * @return bool
     */
    public static function is_user_company_itinerary(Request $request, Itinerary $itinerary): bool
    {
        $user_company = $request()->user()->active_company;
        $itinerary_company = $itinerary->trip->company_id;
        return $user_company->company_id !== $itinerary_company;
    }
}
