<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\TripDay;
use App\Enums\TripStatus;
use App\Enums\BookingStatus;

class Trip extends Model
{
    use HasFactory;

    protected $table = 'trips';
    protected $primaryKey = 'trip_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['trip_id','project_id','created_by','trip_name','description','start_date','end_date','status'];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'status' => TripStatus::class,
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(TripDay::class, 'trip_id', 'trip_id');
    }
}
