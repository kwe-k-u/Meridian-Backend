<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\TripDayDestination;

class TripDay extends Model
{
    use HasFactory;

    protected $table = 'trip_days';
    protected $primaryKey = 'trip_day_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['trip_day_id','trip_id','day_number','date','title','description','location'];

    protected $casts = [
        'date' => 'date',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(TripDayDestination::class, 'trip_day_id', 'trip_day_id');
    }
}
