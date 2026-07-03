<?php

namespace App\Helpers;

use App\Models\Company;
use App\Models\Transaction;

class TransactionHelper
{
    /**
     * Check if a transaction is accessible to a company user
     *
     * @return bool
     */
    public static function is_transaction_accessible(Company $company, Transaction $transaction): bool
    {
        $accessible = false;
        if ($transaction->relationLoaded('tripPayment') || $transaction->tripPayment) {
            $transaction->load('tripPayment.trip');
            $tripCompanyId = optional($transaction->tripPayment->trip)->company_id;
            $accessible = $tripCompanyId == $company->company_id;
        }
        if (!$accessible && $transaction->subscriptionPayment) {
            $accessible = $transaction->subscriptionPayment->company_id == $company->company_id;
        }
        return $accessible;
    }
}
