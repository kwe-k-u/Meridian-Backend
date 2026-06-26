<?php

namespace App\Enums;

enum AccommodationStatus: string
{
    case PENDING = 'pending';
    case BOOKED = 'booked';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
}
