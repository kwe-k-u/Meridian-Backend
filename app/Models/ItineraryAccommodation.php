<?php

namespace App\Models;

use App\Enums\AccommodationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `itinerary_accommodation` table.
 *
 * Purpose: Represents a hotel or accommodation booking within an itinerary.
 *
 * @property string $accommodation_id Unique identifier for the accommodation record.
 * @property string $itinerary_id Foreign key to the associated itinerary.
 * @property AccommodationStatus $status Current booking status (e.g., pending, confirmed, cancelled).
 */
class ItineraryAccommodation extends Model
{
    use HasFactory;

    protected $table = 'itinerary_accommodation';
    protected $primaryKey = 'accommodation_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'accommodation_id',
        'itinerary_id',
        'accommodation_name',
        'address',
        'check_in_date',
        'check_out_date',
        'room_type',
        'cost',
        'currency',
        'booking_reference',
        'booking_url',
        'status',
    ];

    protected $casts = [
        'status' => AccommodationStatus::class,
        'check_in_date' => 'date',
        'check_out_date' => 'date',
    ];

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class, 'itinerary_id', 'itinerary_id');
    }
}
