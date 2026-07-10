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
        'start_city',
        'description',
        'start_date',
        'end_date',
        'status',
        'source_links',
    ];

    protected $casts = [
        'status'       => ItineraryStatus::class,
        'start_date'   => 'date',
        'end_date'     => 'date',
        'source_links' => 'array',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    // Same relation-name/attribute-name collision as Trip::createdBy() — when eager-loaded,
    // this replaces the plain `created_by` user-id string with the loaded User object in JSON.
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    // The day-by-day schedule for this itinerary option, each day optionally carrying
    // linked destinations (see ItineraryDay::destinations()). Ordered by day_number since the
    // frontend maps array position directly to "Day N" (TripDetail.tsx's rawDays/handleAddBlock)
    // — without this, rows without an explicit ORDER BY can come back in an arbitrary order
    // (e.g. by itinerary_day_id, which is time+random and not chronological within a batch
    // insert), silently misaligning which day a UI action actually applies to.
    public function itineraryDays(): HasMany
    {
        return $this->hasMany(ItineraryDay::class, 'itinerary_id', 'itinerary_id')->orderBy('day_number');
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
