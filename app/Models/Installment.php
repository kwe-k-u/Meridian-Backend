<?php

namespace App\Models;

use App\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `installments` table.
 *
 * Purpose: One due amount within a PaymentPlan. A single installment can receive multiple
 * partial WeWire transfers before it's fully PAID — each is its own Transaction, linked via
 * InstallmentPayment (the same 1:1-join-table shape TripPayment uses for Transaction).
 *
 * @property string $id Unique identifier for the installment.
 * @property string $payment_plan_id Foreign key to the owning plan.
 * @property InstallmentStatus $status
 */
class Installment extends Model
{
    use HasFactory;

    protected $table = 'installments';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'payment_plan_id',
        'sequence',
        'amount',
        'currency',
        'due_date',
        'status',
    ];

    protected $casts = [
        'amount' => 'float',
        'due_date' => 'date',
        'status' => InstallmentStatus::class,
    ];

    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class, 'payment_plan_id', 'id');
    }

    public function installmentPayments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class, 'installment_id', 'id');
    }

    // Sum of every *completed* transaction paid against this installment so far.
    public function paidAmount(): float
    {
        $this->loadMissing('installmentPayments.transaction');
        return (float) $this->installmentPayments
            ->filter(fn(InstallmentPayment $ip) => $ip->transaction?->status?->value === 'completed')
            ->sum(fn(InstallmentPayment $ip) => (float) $ip->transaction->amount);
    }
}
