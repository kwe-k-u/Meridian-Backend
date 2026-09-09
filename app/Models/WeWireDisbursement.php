<?php

namespace App\Models;

use App\Enums\DisbursementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `wewire_disbursements` table.
 *
 * Purpose: One payout attempt to an agency's beneficiary account — either automatic (a WeWire
 * virtual account set to FundHandling::DISBURSE paying out a single inbound payment, see
 * WeWirePaymentController::maybeDisburse — sets source_inbound_id) or manually triggered from
 * the dashboard to pay an agency out for everything collected on one trip (see payoutTrip() —
 * sets source_trip_id instead, since it can sweep up several installment payments at once).
 * Updated in place as PENDING -> SUCCESSFUL/FAILED/REVERSED/CANCELLED by the
 * `transaction.status_updated` webhook; a retry creates a new row rather than mutating a
 * failed one, so every attempt stays visible.
 *
 * @property string $id Unique identifier.
 * @property DisbursementStatus $status
 */
class WeWireDisbursement extends Model
{
    use HasFactory;

    protected $table = 'wewire_disbursements';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'wewire_transaction_id',
        'virtual_account_id',
        'beneficiary_id',
        'source_inbound_id',
        'source_trip_id',
        'amount',
        'currency',
        'fee',
        'status',
        'failure_reason',
        'initiated_at',
        'settled_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'fee' => 'float',
        'status' => DisbursementStatus::class,
        'initiated_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(WeWireVirtualAccount::class, 'virtual_account_id', 'id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(WeWireBeneficiary::class, 'beneficiary_id', 'id');
    }

    public function sourceInbound(): BelongsTo
    {
        return $this->belongsTo(WeWireInboundTransaction::class, 'source_inbound_id', 'id');
    }

    public function sourceTrip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'source_trip_id', 'trip_id');
    }
}
