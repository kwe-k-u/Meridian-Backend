<?php

namespace App\Enums;

/**
 * Billing/account standing of a company. Not currently referenced anywhere in the codebase —
 * App\Models\Company stores `status` as a plain boolean instead. Kept for a future richer
 * status model; use App\Enums\SubscriptionStatus for the subscription lifecycle that IS live.
 */
enum CompanyStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case PAST_DUE = 'past_due';
}