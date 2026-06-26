<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
