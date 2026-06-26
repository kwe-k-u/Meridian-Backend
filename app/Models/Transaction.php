<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'status' => TransactionStatus::class,
        'paid_at' => 'datetime',
    ];

    public function subscriptionPayment(): HasOne
    {
        return $this->hasOne(SubscriptionPayment::class, 'transaction_id', 'transaction_id');
    }

    public function tripPayment(): HasOne
    {
        return $this->hasOne(TripPayment::class, 'transaction_id', 'transaction_id');
    }
}
