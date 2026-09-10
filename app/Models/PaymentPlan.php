<?php

namespace App\Models;

use App\Enums\PaymentPlanStatus;
use App\Enums\PaymentPlanType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model for the `payment_plans` table.
 *
 * Purpose: A trip's collection plan — either a single lump-sum installment or a fixed set of
 * installments. `payment_reference` is the short code (see App\Services\ReferenceCodeGenerator)
 * a customer quotes to look the plan up on the public /pay/:reference page and to have their
 * transfer reconciled against it.
 *
 * A trip can have up to one plan per `plan_type` (see unique(trip_id, plan_type)) — typically
 * the FULL + INSTALLMENTS pair auto-created on itinerary acceptance (see
 * App\Services\DefaultPaymentPlanService), plus optionally one hand-built CUSTOM plan.
 *
 * @property string $id Unique identifier for the plan.
 * @property string $trip_id Foreign key to the trip this plan belongs to.
 * @property string $payment_reference 8-char lookup/reconciliation code, e.g. ABCDE123.
 * @property PaymentPlanType $plan_type
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
        'plan_type',
        'status',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'plan_type' => PaymentPlanType::class,
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
