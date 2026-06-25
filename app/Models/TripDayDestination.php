<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripDayDestination extends Model
{
    use HasFactory;

    protected $table = 'trip_day_destinations';
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = null;

    protected $fillable = ['trip_day_id','destination_id','cost','currency','activities','booking_url'];
    
    protected $casts = [
        'cost' => 'decimal:2',
    ];

    public function tripDay(): BelongsTo
    {
        return $this->belongsTo(TripDay::class, 'trip_day_id', 'trip_day_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'destination_id', 'destination_id');
    }
}
