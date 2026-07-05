<?php

namespace App\Enums;

/**
 * A customer's role on a specific trip (stored on the trip_customers pivot table).
 * Defaults to PRIMARY when TripController::addCustomer is called without an explicit role.
 */
enum TripCustomerRole: string
{
    case PRIMARY = 'primary';
    case COMPANION = 'companion';
}
