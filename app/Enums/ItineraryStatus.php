<?php

namespace App\Enums;

enum ItineraryStatus: string
{
    case DRAFT = 'draft';
    case PLANNING = 'planning';
    case CONFIRMED = 'confirmed';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
