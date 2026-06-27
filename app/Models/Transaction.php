<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Model for the `transactions` table.
 *
 * Purpose: Represents a financial transaction record that can be linked to either a trip payment or a subscription payment.
 *
 * @property string $transaction_id Unique identifier for the transaction.
 * @property TransactionStatus $status Current status (e.g., pending, completed, failed).
 */
class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';
    protected $primaryKey = 'transaction_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'transaction_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'transaction_reference',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'status' => TransactionStatus::class,
        'paid_at' => 'datetime',
    ];

    protected $appends = ['client_name'];

    public function getClientNameAttribute(): ?string
    {
        if ($this->relationLoaded('tripPayment') && $this->tripPayment) {
            $trip = $this->tripPayment->trip;
            if ($trip && $trip->relationLoaded('customers') && $trip->customers->isNotEmpty()) {
                return $trip->customers->first()->first_name . ' ' . $trip->customers->first()->last_name;
            }
        }
        return null;
    }

    public function subscriptionPayment(): HasOne
    {
        return $this->hasOne(SubscriptionPayment::class, 'transaction_id', 'transaction_id');
    }

    public function tripPayment(): HasOne
    {
        return $this->hasOne(TripPayment::class, 'transaction_id', 'transaction_id');
    }
}
