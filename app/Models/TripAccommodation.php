<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\BookingStatus;

class TripAccommodation extends Model
{
    use HasFactory;

    protected $table = 'trip_accommodation';
    protected $primaryKey = 'accommodation_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['accommodation_id','trip_id','accommodation_name','address','check_in_date','check_out_date','room_type','cost','currency','booking_reference','booking_url','status'];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
        'cost' => 'decimal:2',
        'status' => BookingStatus::class,
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }
}
