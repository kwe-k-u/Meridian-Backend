<?php

namespace App\Enums;

enum BookingStatus: string
{
    case PENDING = 'pending';
    case BOOKED = 'booked';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
}
