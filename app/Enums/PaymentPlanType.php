<?php

namespace App\Enums;

/**
 * Which kind of payment plan a PaymentPlan row is — a trip can have at most one of each (see
 * the unique(trip_id, plan_type) constraint). FULL and INSTALLMENTS are the two defaults
 * auto-created when an itinerary is accepted (App\Services\DefaultPaymentPlanService), so the
 * traveler can choose which to pay through on the public /pay/:reference page. CUSTOM is a
 * staff-defined plan created by hand via PaymentPlanController::store — the original,
 * pre-existing flow.
 */
enum PaymentPlanType: string
{
    case FULL = 'full';
    case INSTALLMENTS = 'installments';
    case CUSTOM = 'custom';
}
