<?php

namespace App\Enums;

/**
 * Reconciliation status of an inbound WeWire transfer (App\Models\WeWireInboundTransaction).
 * A `transaction.pay_in` webhook always creates one of these first; it only becomes MATCHED
 * once we've resolved it to a specific Installment (automatically, by the customer-quoted
 * reference code, or manually by agency staff — see WeWirePaymentController::matchInbound).
 * RECONCILED means the resulting Transaction/InstallmentPayment has been created.
 */
enum InboundMatchStatus: string
{
    case UNMATCHED = 'unmatched';
    case MATCHED = 'matched';
    case RECONCILED = 'reconciled';
}
