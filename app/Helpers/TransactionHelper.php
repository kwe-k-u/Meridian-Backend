<?php

namespace App\Helpers;

use App\Models\Company;
use App\Models\Transaction;

class TransactionHelper
{
    /**
     * A Transaction row is generic (just amount/currency/status) and gets its meaning
     * from exactly one of two "detail" tables it's linked to: TripPayment (paying for a
     * trip) or SubscriptionPayment (paying for the company's subscription). This checks
     * both possibilities and returns true if either one traces back to $company —
     * used by TransactionController::show/updateStatus to block cross-company access.
     *
     * @return bool
     */
    public static function is_transaction_accessible(Company $company, Transaction $transaction): bool
    {
        $accessible = false;
        // Path 1: it's a trip payment — check the trip it's attached to.
        if ($transaction->relationLoaded('tripPayment') || $transaction->tripPayment) {
            $transaction->load('tripPayment.trip');
            $tripCompanyId = optional($transaction->tripPayment->trip)->company_id;
            $accessible = $tripCompanyId == $company->company_id;
        }
        // Path 2: it's a subscription payment — check the company it's billed to directly.
        if (!$accessible && $transaction->subscriptionPayment) {
            $accessible = $transaction->subscriptionPayment->company_id == $company->company_id;
        }
        return $accessible;
    }
}
