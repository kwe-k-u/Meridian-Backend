<?php

namespace App\Enums;

/**
 * Status of a single installment (App\Models\Installment) within a PaymentPlan.
 */
enum InstallmentStatus: string
{
    case PENDING = 'pending';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case OVERDUE = 'overdue';
}
