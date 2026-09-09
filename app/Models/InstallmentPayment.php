<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `installment_payments` table.
 *
 * Purpose: Links a financial transaction to a specific installment, mirroring
 * App\Models\TripPayment's shape exactly (1:1 with Transaction via a shared primary key).
 *
 * @property string $transaction_id Primary key; foreign key to the underlying transaction.
 * @property string $installment_id Foreign key to the associated installment.
 */
class InstallmentPayment extends Model
{
    use HasFactory;

    protected $table = 'installment_payments';
    protected $primaryKey = 'transaction_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'installment_id',
        'notes',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class, 'installment_id', 'id');
    }
}
