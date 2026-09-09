<?php

namespace App\Enums;

/**
 * Lifecycle status of a company's subscription (App\Models\CompanySubscription).
 * PAST_DUE means a renewal payment failed but access hasn't been cut off yet.
 * PENDING means the subscription record was created ahead of a hosted-checkout payment
 * (see App\Http\Controllers\PaystackPaymentController) and is awaiting confirmation before
 * it's flipped to ACTIVE (or CANCELLED, if the payment fails/is abandoned).
 */
enum SubscriptionStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case PAST_DUE = 'past_due';
    case PENDING = 'pending';
}