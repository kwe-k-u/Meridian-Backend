<?php

namespace App\Enums;

enum TripStatus: string
{
    case INQUIRY = 'inquiry';
    case PLANNING = 'planning';
    case BOOKED = 'booked';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
