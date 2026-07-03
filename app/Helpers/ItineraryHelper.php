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
        return $user_company->company_id == $itinerary_company;
    }

    public static function calculateItineraryCost(Itinerary $itin): array
    {
        $flightsCost = (float) ($itin?->itineraryFlights->sum('cost') ?? 0);
        $accommodationCost = (float) ($itin?->itineraryAccommodation->sum('cost') ?? 0);
        $activitiesCost = (float) ($itin?->itineraryDays->flatMap(fn($d) => $d->destinations)->sum('cost') ?? 0);

        $subtotal = $flightsCost + $accommodationCost + $activitiesCost;
        $serviceFee = round($subtotal * 0.05, 2);
        $total = $subtotal + $serviceFee;

        $currency = $itin?->itineraryFlights->first()?->currency
            ?? $itin?->itineraryAccommodation->first()?->currency
            ?? 'GHS';

        return [
            'itinerary_id' => $itin->id,
            'currency' => $currency,
            'flights' => $flightsCost,
            'accommodation' => $accommodationCost,
            'activities' => $activitiesCost,
            'subtotal' => $subtotal,
            'service_fee' => $serviceFee,
            'total' => $total,
        ];
    }
}
