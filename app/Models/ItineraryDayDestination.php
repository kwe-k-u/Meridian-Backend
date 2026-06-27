<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model for the `itinerary_day_destinations` pivot table.
 *
 * Purpose: Links destinations to a specific itinerary day, storing per-destination cost, currency, activities, and booking URL.
 *
 * @property string $itinerary_day_id Foreign key to the itinerary day.
 * @property string $destination_id Foreign key to the destination.
 */
class ItineraryDayDestination extends Model
{
    use HasFactory;

    protected $table = 'itinerary_day_destinations';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'itinerary_day_id',
        'destination_id',
        'cost',
        'currency',
        'activities',
        'booking_url',
    ];
}
