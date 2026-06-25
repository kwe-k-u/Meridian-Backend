<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\BookingStatus;

class TripFlight extends Model
{
    use HasFactory;

    protected $table = 'trip_flights';
    protected $primaryKey = 'flight_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['flight_id','trip_id','airline','flight_number','departure_airport','arrival_airport','departure_datetime','arrival_datetime','cost','currency','booking_reference','booking_url','status'];

    protected $casts = [
        'departure_datetime' => 'datetime',
        'arrival_datetime' => 'datetime',
        'cost' => 'decimal:2',
        'status' => BookingStatus::class,
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
