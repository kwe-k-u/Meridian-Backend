<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `trip_customers` pivot table.
 *
 * Purpose: Links customers to a trip and defines their role (e.g., lead traveler, companion) within that trip.
 *
 * @property string $trip_id Foreign key to the trip.
 * @property string $customer_id Foreign key to the customer.
 */
class TripCustomer extends Model
{
    use HasFactory;

    protected $table = 'trip_customers';
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'trip_id',
        'customer_id',
        'role',
        'added_at',
    ];

    protected $casts = [
        'added_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'customer_id');
    }
}
