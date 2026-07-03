<?php

namespace App\Helpers;

use App\Models\Destination;
use Illuminate\Http\Request;

class DestinationHelper
{
    /**
     * Get user company
     *
     * @return bool
     */
    public static function user_company_dest(Request $request, Destination $destination): bool
    {
        $company = $request->user()->active_company;
        $itinerary_company = $destination->itineraryDays->first()->itinerary->trip->company_id;
        return $company->company_id == $itinerary_company;
    }
}