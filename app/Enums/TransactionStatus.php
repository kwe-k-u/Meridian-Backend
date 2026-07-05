<?php

namespace App\Enums;

/**
 * Status of a financial transaction (App\Models\Transaction), which is either a
 * trip payment or a subscription payment (see App\Models\TripPayment / SubscriptionPayment).
 * DashboardController sums by this status to compute revenue/outstanding/refunds.
 */
enum TransactionStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case REFUNDED = 'refunded';
}
