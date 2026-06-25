<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\TripDayDestination;

class Destination extends Model
{
    use HasFactory;

    protected $table = 'destinations';
    protected $primaryKey = 'destination_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = ['destination_id','name','country','url'];

    public function tripDayDestinations(): HasMany
    {
        return $this->hasMany(TripDayDestination::class, 'destination_id', 'destination_id');
    }
}
