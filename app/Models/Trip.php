<?php

namespace App\Models;

use App\Enums\TripStatus;
use App\Enums\TripCustomerRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `trips` table.
 *
 * Purpose: Central entity representing a travel trip/project created by a company, linking customers, itineraries, calls, and payments.
 *
 * @property string $trip_id Unique identifier for the trip.
 * @property string $company_id Foreign key to the owning company.
 * @property string $created_by Foreign key to the user who created the trip.
 * @property TripStatus $status Current status (e.g., planning, confirmed, in_progress, completed).
 */
class Trip extends Model
{
    use HasFactory;

    protected $table = 'trips';
    protected $primaryKey = 'trip_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'trip_id',
        'company_id',
        'created_by',
        'trip_name',
        'description',
        'start_date',
        'end_date',
        'budget',
        'status',
    ];

    protected $casts = [
        'status' => TripStatus::class,
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    // Note: eager-loading this relation (as index()/show() do) makes Eloquent overwrite the
    // plain `created_by` string attribute with the loaded User object in the JSON response,
    // because Laravel snake_cases the relation name `createdBy` to the same key `created_by`.
    // That's why TripResponse.created_by is typed as `string | {user_id, display_name} | null`
    // on the frontend — string when not loaded, object when it is.
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    // Travelers assigned to this trip (e.g. lead traveler + companions), via trip_customers.
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'trip_customers', 'trip_id', 'customer_id')
            ->withPivot(['role', 'added_at']);
    }

    // The itinerary "options" generated for this trip (Option A/B/C...) — see
    // TripController::generateItinerary() and ItineraryController.
    public function itineraries(): HasMany
    {
        return $this->hasMany(Itinerary::class, 'trip_id', 'trip_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class, 'trip_id', 'trip_id');
    }

    // Payments recorded against this trip (each wraps one Transaction) — see
    // TransactionController::recordTripPayment() and TripController::costs().
    public function tripPayments(): HasMany
    {
        return $this->hasMany(TripPayment::class, 'trip_id', 'trip_id');
    }
}
