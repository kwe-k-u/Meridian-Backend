<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'itinerary_day_destinations', 'itinerary_day_id', 'destination_id')
            ->withPivot(['cost', 'currency', 'activities', 'booking_url']);
    }
}
