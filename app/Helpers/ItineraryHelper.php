<?php

namespace App\Helpers;

use App\Models\Company;
use App\Models\Itinerary;
use Illuminate\Http\Request;

/**
 * Authorization and cost-calculation helpers for itineraries and everything nested
 * under them (days, flights, accommodation, day-destinations). ItineraryController
 * uses is_user_company_itinerary() as its access check on every sub-resource route,
 * and TripController::costs()/generateItinerary() lean on calculateItineraryCost().
 */
class ItineraryHelper
{
    /**
     * Whether the authenticated user's active company owns the trip this itinerary
     * belongs to. Used to gate every itinerary/day/flight/accommodation mutation so
     * one company can never read or edit another company's itinerary data.
     *
     * @return bool
     */
    public static function is_user_company_itinerary(Request $request, Itinerary $itinerary): bool
    {
        $user_company = $request->user()->active_company;
        $itinerary_company = $itinerary->trip->company_id;
        return $user_company->company_id == $itinerary_company;
    }

    /**
     * Add up everything booked under one itinerary (flights + accommodation + the cost
     * of each destination attached to a day) and layer a flat 5% service fee on top.
     *
     * Assumes itineraryFlights / itineraryAccommodation / itineraryDays.destinations are
     * already eager-loaded on $itin — this does not run additional queries itself.
     * Currency is taken from whichever flight or accommodation row happens to specify
     * one first; if nothing has a currency yet it falls back to GHS.
     *
     * Returned shape matches the per-itinerary entries in TripController::costs()'s
     * `itineraries` array and is consumed by the frontend as TripCostResponse['itineraries'][number].
     */
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
            'itinerary_id' => $itin->itinerary_id,
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
