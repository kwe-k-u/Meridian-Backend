<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `itinerary_days` table.
 *
 * Purpose: Represents a single day within an itinerary, containing a schedule and linked destinations.
 *
 * @property string $itinerary_day_id Unique identifier for the itinerary day.
 * @property string $itinerary_id Foreign key to the associated itinerary.
 * @property int $day_number The sequential day number within the itinerary.
 */
class ItineraryDay extends Model
{
    use HasFactory;

    protected $table = 'itinerary_days';
    protected $primaryKey = 'itinerary_day_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'itinerary_day_id',
        'itinerary_id',
        'day_number',
        'date',
        'title',
        'description',
        'location',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class, 'itinerary_id', 'itinerary_id');
    }

    // Destinations/activities attached to this specific day. Deliberately a HasMany to the
    // pivot model (ItineraryDayDestination) rather than a BelongsToMany straight to
    // Destination — a plain BelongsToMany buries cost/currency/activities/booking_url under
    // Eloquent's `pivot` key in JSON output and leaves no `cost` attribute on the Destination
    // model itself, which silently broke both the frontend's block rendering (always fell
    // back to a literal "Activity" placeholder) and ItineraryHelper::calculateItineraryCost()
    // (summing a non-existent `cost` column always totalled 0). Eager-load
    // 'itineraryDays.destinations.destination' to also get each attachment's Destination row.
    public function destinations(): HasMany
    {
        return $this->hasMany(ItineraryDayDestination::class, 'itinerary_day_id', 'itinerary_day_id');
    }
}
