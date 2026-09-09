<?php

namespace App\Enums;

/**
 * Status of a WeWire payout/disbursement (App\Models\WeWireDisbursement). Mirrors WeWire's
 * own transaction lifecycle for a payout: PENDING -> SUCCESSFUL, FAILED, REVERSED, or
 * CANCELLED — see `transaction.status_updated` webhook (WeWirePaymentController::
 * handleTransactionStatusUpdated). INITIATION_FAILED is Meridian-specific: it means the
 * initiate-payout API call itself never got an id back from WeWire, so there's nothing for a
 * later webhook to update — distinct from FAILED, which is WeWire reporting a payout it did
 * accept later failed.
 */
enum DisbursementStatus: string
{
    case PENDING = 'pending';
    case SUCCESSFUL = 'successful';
    case FAILED = 'failed';
    case REVERSED = 'reversed';
    case CANCELLED = 'cancelled';
    case INITIATION_FAILED = 'initiation_failed';
}
