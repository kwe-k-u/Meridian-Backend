<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    use HasFactory;

    protected $table = 'subscription_payments';
    protected $primaryKey = 'transaction_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'transaction_id', 'subscription_id', 'company_id', 'initiated_by'
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id', 'transaction_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'subscription_id', 'subscription_id');
    }
}
