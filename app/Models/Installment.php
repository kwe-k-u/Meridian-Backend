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

    // Sum of every *completed* transaction paid against this installment so far, converted
    // into this installment's own currency. A traveler can pay through any of the WeWire
    // options offered on the public pay page (USD/GHS account, or crypto) regardless of which
    // currency the plan/installment itself is denominated in — see WeWirePaymentController::
    // buildLookupResponse — so a transaction's own currency won't always match this
    // installment's, and summing the raw numbers together would silently over/under-count.
    public function paidAmount(): float
    {
        $this->loadMissing('installmentPayments.transaction');
        return round((float) $this->installmentPayments
            ->filter(fn(InstallmentPayment $ip) => $ip->transaction?->status?->value === 'completed')
            ->sum(fn(InstallmentPayment $ip) => \App\Services\CurrencyService::convert(
                (float) $ip->transaction->amount,
                $ip->transaction->currency,
                $this->currency,
            )), 2);
    }
}
