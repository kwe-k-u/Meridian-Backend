<?php

namespace App\Models;

use App\Enums\ItineraryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
