<?php

namespace App\Models;

use App\Enums\ItineraryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `itinerary` table.
 *
 * Purpose: Represents a trip itinerary plan containing daily schedules, flights, and accommodation bookings.
 *
 * @property string $itinerary_id Unique identifier for the itinerary.
 * @property string $trip_id Foreign key to the associated trip.
 * @property string $created_by Foreign key to the user who created the itinerary.
 * @property ItineraryStatus $status Current status (e.g., draft, confirmed, cancelled).
 */
class Itinerary extends Model
{
    use HasFactory;

    protected $table = 'itinerary';
    protected $primaryKey = 'itinerary_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'itinerary_id',
        'trip_id',
        'created_by',
        'itinerary_name',
        'description',
        'start_date',
        'end_date',
        'status',
    ];

    protected $casts = [
        'status' => ItineraryStatus::class,
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function itineraryDays(): HasMany
    {
        return $this->hasMany(ItineraryDay::class, 'itinerary_id', 'itinerary_id');
    }

    public function itineraryFlights(): HasMany
    {
        return $this->hasMany(ItineraryFlight::class, 'itinerary_id', 'itinerary_id');
    }

    public function itineraryAccommodation(): HasMany
    {
        return $this->hasMany(ItineraryAccommodation::class, 'itinerary_id', 'itinerary_id');
    }
}
