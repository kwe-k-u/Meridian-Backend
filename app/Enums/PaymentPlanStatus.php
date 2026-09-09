<?php

namespace App\Enums;

/**
 * Lifecycle status of a trip's installment plan (App\Models\PaymentPlan).
 * ACTIVE means at least one installment is outstanding; COMPLETED once every installment
 * has been paid in full.
 */
enum PaymentPlanStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
