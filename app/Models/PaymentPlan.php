<?php

namespace App\Models;

use App\Enums\PaymentPlanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `payment_plans` table.
 *
 * Purpose: A trip's collection plan — either a single lump-sum installment or a fixed set of
 * installments the agency defines. `payment_reference` is the short code (see
 * App\Services\ReferenceCodeGenerator) a customer quotes to look the plan up on the public
 * /pay/:reference page and to have their transfer reconciled against it.
 *
 * @property string $id Unique identifier for the plan.
 * @property string $trip_id Foreign key to the trip this plan belongs to (one plan per trip).
 * @property string $payment_reference 8-char lookup/reconciliation code, e.g. ABCDE123.
 * @property PaymentPlanStatus $status
 */
class PaymentPlan extends Model
{
    use HasFactory;

    protected $table = 'payment_plans';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'trip_id',
        'payment_reference',
        'total_amount',
        'currency',
        'status',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'status' => PaymentPlanStatus::class,
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id', 'trip_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class, 'payment_plan_id', 'id')->orderBy('sequence');
    }
}
