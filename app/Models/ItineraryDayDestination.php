<?php

namespace App\Models;

use App\Enums\DestinationItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `itinerary_day_destinations` pivot table.
 *
 * Purpose: Links destinations to a specific itinerary day, storing per-destination cost, currency, activities, and booking URL.
 *
 * Accessed as ItineraryDay::destinations() — a HasMany to this model (not a BelongsToMany to
 * Destination directly), specifically so `cost`/`currency`/`activities`/`booking_url` are
 * plain top-level attributes rather than being buried under Eloquent's `pivot` key. The
 * frontend (ItineraryDayResponse['destinations'] in types/app.ts) expects exactly this flat
 * shape plus a nested `destination` object — see the destination() relation below.
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
        'item_type',
        'cost',
        'currency',
        'activities',
        'booking_url',
    ];

    protected $casts = [
        'item_type' => DestinationItemType::class,
    ];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_id', 'destination_id');
    }
}
