<?php

namespace App\Helpers;

use App\Models\Destination;
use Illuminate\Http\Request;

class DestinationHelper
{
    /**
     * Whether the authenticated user's company is allowed to view/edit this destination.
     *
     * Destinations are a shared lookup table (not created per-company), so "ownership"
     * here is inferred indirectly: find any itinerary day the destination has been
     * attached to, then check whether that day's itinerary's trip belongs to the
     * user's company.
     *
     * Caveat: this assumes the destination is already linked to at least one itinerary
     * day. A destination that was just created via DestinationController::store() (which
     * doesn't call this check) but not yet attached to any day will have an empty
     * itineraryDays collection, so `->first()` returns null and `->itinerary` on null
     * throws a fatal error. In practice this only bites DestinationController::show/update/destroy
     * for a brand-new, unattached destination — worth guarding if that becomes a real flow.
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