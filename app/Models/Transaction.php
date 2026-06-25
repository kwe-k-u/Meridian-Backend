<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\SubscriptionPayment;
use App\Enums\TransactionStatus;

class Transaction extends Model
{
    use HasFactory;

    protected $table = 'transactions';
    protected $primaryKey = 'transaction_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'transaction_id', 'amount', 'currency', 'status', 'payment_method', 'transaction_reference', 'paid_at'
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'amount' => 'decimal:2',
        'status' => TransactionStatus::class,
    ];

    public function subscriptionPayment(): HasOne
    {
        return $this->hasOne(SubscriptionPayment::class, 'transaction_id', 'transaction_id');
    }
}
