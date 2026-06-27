<?php

namespace App\Models;

use App\Enums\FlightStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `itinerary_flights` table.
 *
 * Purpose: Represents a flight booking within an itinerary, including departure/arrival details and cost.
 *
 * @property string $flight_id Unique identifier for the flight record.
 * @property string $itinerary_id Foreign key to the associated itinerary.
 * @property FlightStatus $status Current booking status (e.g., pending, confirmed, cancelled).
 */
class ItineraryFlight extends Model
{
    use HasFactory;

    protected $table = 'itinerary_flights';
    protected $primaryKey = 'flight_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'flight_id',
        'itinerary_id',
        'airline',
        'flight_number',
        'departure_airport',
        'arrival_airport',
        'departure_datetime',
        'arrival_datetime',
        'cost',
        'currency',
        'booking_reference',
        'booking_url',
        'status',
    ];

    protected $casts = [
        'status' => FlightStatus::class,
        'departure_datetime' => 'datetime',
        'arrival_datetime' => 'datetime',
    ];

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class, 'itinerary_id', 'itinerary_id');
    }
}
