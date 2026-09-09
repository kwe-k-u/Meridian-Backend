<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `wewire_beneficiaries` table.
 *
 * Purpose: A bank account a company can disburse WeWire funds to (see
 * App\Models\WeWireVirtualAccount.beneficiary_account_id).
 *
 * @property string $id Unique identifier for the beneficiary.
 * @property string $company_id Foreign key to the owning company.
 */
class WeWireBeneficiary extends Model
{
    use HasFactory;

    protected $table = 'wewire_beneficiaries';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'wewire_beneficiary_id',
        'currency',
        'account_name',
        'bank_name',
        'account_number',
        'iban',
        'sort_code',
        'routing_number',
        'swift_bic',
        'settlement_method',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }
}
