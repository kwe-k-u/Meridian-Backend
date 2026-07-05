<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Model for the `destinations` table.
 *
 * Purpose: Represents a travel destination or point of interest that can be linked to itinerary days.
 *
 * @property string $destination_id Unique identifier for the destination.
 */
class Destination extends Model
{
    use HasFactory;

    protected $table = 'destinations';
    protected $primaryKey = 'destination_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'destination_id',
        'name',
        'country',
        'url',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Every itinerary day this destination has been attached to, across all itineraries/companies
    // (destinations are a shared lookup table, not owned by one company). See
    // DestinationHelper::user_company_dest() for how "does this destination belong to my
    // company" is inferred from this relation.
    public function itineraryDays(): BelongsToMany
    {
        return $this->belongsToMany(ItineraryDay::class, 'itinerary_day_destinations', 'destination_id', 'itinerary_day_id')
            ->withPivot(['cost', 'currency', 'activities', 'booking_url']);
    }
}
