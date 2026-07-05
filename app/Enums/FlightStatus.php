<?php

namespace App\Enums;

/**
 * Booking status of a single flight leg on an itinerary (App\Models\ItineraryFlight).
 * PENDING = not yet ticketed, BOOKED = ticketed, CONFIRMED = airline confirmed.
 */
enum FlightStatus: string
{
    case PENDING = 'pending';
    case BOOKED = 'booked';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
}
