<?php

namespace App\Enums;

/**
 * Lifecycle status of a trip (App\Models\Trip). Roughly: INQUIRY/PLANNING (still being
 * put together) -> BOOKED -> IN_PROGRESS -> COMPLETED, with CANCELLED possible at any point.
 * The frontend re-labels these for display (e.g. `planning` shows as "Draft") — see
 * apiStatusMeta in the frontend's AppContext.tsx/TripDetail.tsx/Trips.tsx.
 */
enum TripStatus: string
{
    case INQUIRY = 'inquiry';
    case PLANNING = 'planning';
    case BOOKED = 'booked';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
