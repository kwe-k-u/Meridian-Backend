<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `subscription_payments` table.
 *
 * Purpose: Links a financial transaction to a company's subscription renewal, recording who initiated it.
 *
 * @property string $transaction_id Primary key; foreign key to the underlying transaction.
 * @property string $subscription_id Foreign key to the company subscription being paid for.
 * @property string $company_id Foreign key to the company making the payment.
 * @property string $initiated_by Foreign key to the user who initiated the payment.
 */
class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $table = 'subscription_payments';
    protected $primaryKey = 'transaction_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'transaction_id',
        'subscription_id',
        'company_id',
        'initiated_by',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'subscription_id', 'subscription_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by', 'user_id');
    }
}
