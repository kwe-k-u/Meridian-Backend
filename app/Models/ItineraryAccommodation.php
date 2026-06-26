<?php

namespace App\Models;

use App\Enums\AccommodationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
