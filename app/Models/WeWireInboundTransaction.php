<?php

namespace App\Models;

use App\Enums\InboundMatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `wewire_inbound_transactions` table.
 *
 * Purpose: One row per `transaction.pay_in` webhook WeWire sends us (keyed by
 * wewire_transaction_id for idempotency), whether or not we could auto-match it to an
 * Installment by its quoted reference code. Unmatched rows surface in the Settings > Payments
 * reconciliation queue for agency staff to resolve manually.
 *
 * @property string $id Unique identifier.
 * @property InboundMatchStatus $status
 */
class WeWireInboundTransaction extends Model
{
    use HasFactory;

    protected $table = 'wewire_inbound_transactions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'wewire_transaction_id',
        'virtual_account_id',
        'crypto_wallet_id',
        'tx_hash',
        'amount',
        'currency',
        'reference_raw',
        'matched_payment_reference',
        'status',
        'is_simulated',
        'installment_id',
        'transaction_id',
        'received_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'status' => InboundMatchStatus::class,
        'is_simulated' => 'boolean',
        'received_at' => 'datetime',
    ];

    public function virtualAccount(): BelongsTo
    {
        return $this->belongsTo(WeWireVirtualAccount::class, 'virtual_account_id', 'id');
    }

    public function cryptoWallet(): BelongsTo
    {
        return $this->belongsTo(WeWireCryptoWallet::class, 'crypto_wallet_id', 'id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class, 'installment_id', 'id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }
}
