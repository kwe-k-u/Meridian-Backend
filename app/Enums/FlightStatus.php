<?php

namespace App\Enums;

enum FlightStatus: string
{
    case PENDING = 'pending';
    case BOOKED = 'booked';
    case CONFIRMED = 'confirmed';
    case CANCELLED = 'cancelled';
}
