<?php

namespace App\Enums;

/**
 * Lifecycle status of a company's subscription (App\Models\CompanySubscription).
 * PAST_DUE means a renewal payment failed but access hasn't been cut off yet.
 */
enum SubscriptionStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case PAST_DUE = 'past_due';
}