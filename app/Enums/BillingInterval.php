<?php

namespace App\Enums;

/**
 * How often a subscription tier would be billed (App\Models\SubscriptionTier).
 * Not currently referenced/cast anywhere else — reserved for when billing-interval
 * selection is wired up.
 */
enum BillingInterval: string
{
    case QUARTERLY = 'quarterly';
    case YEARLY = 'yearly';
}
