<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Model for the `customers` table.
 *
 * Purpose: Represents an end-client traveler managed by a company and associated with trips.
 *
 * @property string $customer_id Unique identifier for the customer.
 * @property string $company_id Foreign key to the owning company.
 * @property CustomerStatus $status Current status (e.g., active, inactive).
 */
class Customer extends Model
{
    use HasFactory;

    protected $table = 'customers';
    protected $primaryKey = 'customer_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'customer_id',
        'company_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'nationality',
        'date_of_birth',
        'passport_number',
        'notes',
        'status',
    ];

    protected $casts = [
        'status' => CustomerStatus::class,
        'date_of_birth' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function trips(): BelongsToMany
    {
        return $this->belongsToMany(Trip::class, 'trip_customers', 'customer_id', 'trip_id')
            ->withPivot(['role', 'added_at']);
    }
}
