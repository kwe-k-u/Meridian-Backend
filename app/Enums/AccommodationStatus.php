<?php

namespace App\Enums;

/**
 * Booking status of a single accommodation line item on an itinerary (App\Models\ItineraryAccommodation).
 * Mirrors App\Enums\FlightStatus (pending -> booked -> confirmed, or cancelled at any point).
 */
enum AccommodationStatus: string
{
    case PENDING = 'pending';
    case BOOKED = 'booked';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
}
