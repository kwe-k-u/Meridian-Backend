<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `companies` table.
 *
 * Purpose: Represents a travel agency company that manages trips, customers, and subscriptions.
 *
 * @property string $company_id Unique identifier for the company.
 */
class Company extends Model
{
    use HasFactory;

    protected $table = 'companies';
    protected $primaryKey = 'company_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'company_name',
        'country',
        'city_of_operation',
        'status',
        'preferred_currency',
        'wewire_subcustomer_id',
        'wewire_kyc_status',
    ];

    // The team members (users) that belong to this company, e.g. for the Settings > Team page.
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_companies', 'company_id', 'user_id')
            ->withPivot(['role', 'is_default', 'is_enabled', 'joined_at']);
    }

    // Every trip this company has created, regardless of status.
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'company_id', 'company_id');
    }

    // This company's WeWire multi-currency collection accounts (up to 3, one per currency).
    public function wewireAccounts(): HasMany
    {
        return $this->hasMany(WeWireVirtualAccount::class, 'company_id', 'company_id');
    }

    // Bank accounts this company can disburse WeWire funds to.
    public function wewireBeneficiaries(): HasMany
    {
        return $this->hasMany(WeWireBeneficiary::class, 'company_id', 'company_id');
    }
}