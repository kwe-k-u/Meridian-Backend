<?php

namespace App\Enums;

/**
 * What kind of thing a destination attachment on an itinerary day actually is
 * (App\Models\ItineraryDayDestination). Purely descriptive — drives the icon/badge shown in
 * the frontend's day-block cards (see blockKindMeta in TripDetail.tsx) and has no other
 * behavioral effect.
 */
enum DestinationItemType: string
{
    case ACTIVITY = 'activity';
    case DINING = 'dining';
    case TRANSFER = 'transfer';
    case VENUE = 'venue';
}
