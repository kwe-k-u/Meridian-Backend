<?php

namespace App\Models;

use App\Enums\FundHandling;
use App\Enums\VirtualAccountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `wewire_virtual_accounts` table.
 *
 * Purpose: One multi-currency collection account for a company (up to 3 per company, one per
 * currency — see the unique(company_id, currency) constraint). Customers pay a trip's
 * installments into whichever of these matches the trip's currency.
 *
 * @property string $id Unique identifier for the virtual account.
 * @property string $company_id Foreign key to the owning company.
 * @property VirtualAccountStatus $status
 * @property FundHandling $fund_handling Hold in the wallet, or auto-disburse to a beneficiary.
 */
class WeWireVirtualAccount extends Model
{
    use HasFactory;

    // Currencies WeWire will issue a virtual account in for a BUSINESS sub-customer (GHS is
    // business-only; individuals don't get it — see docs.wewire.com/concepts/virtual-accounts).
    public const SUPPORTED_CURRENCIES = ['USD', 'EUR', 'GBP', 'GHS'];
    public const MAX_ACCOUNTS_PER_COMPANY = 3;

    protected $table = 'wewire_virtual_accounts';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'currency',
        'wewire_account_id',
        'status',
        'account_number',
        'iban',
        'sort_code',
        'routing_number',
        'fund_handling',
        'beneficiary_account_id',
        'is_simulated',
    ];

    protected $casts = [
        'status' => VirtualAccountStatus::class,
        'fund_handling' => FundHandling::class,
        'is_simulated' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(WeWireBeneficiary::class, 'beneficiary_account_id', 'id');
    }
}
