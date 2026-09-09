<?php

namespace App\Services;

use App\Models\PaymentPlan;

/**
 * Generates the customer-facing payment reference code used to look up a PaymentPlan on the
 * public /pay/:reference page and to reconcile inbound WeWire transfers (see
 * WeWirePaymentController). Format: 5 uppercase letters + 3 digits, e.g. ABCDE123 — chosen to
 * be short enough for a customer to type into a bank transfer's reference field, and visually
 * distinct from Meridian's other {PREFIX}_{16 hex/alnum} IDs.
 */
class ReferenceCodeGenerator
{
    private const LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public static function generate(): string
    {
        do {
            $letters = '';
            for ($i = 0; $i < 5; $i++) {
                $letters .= self::LETTERS[random_int(0, strlen(self::LETTERS) - 1)];
            }
            $digits = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
            $code = $letters . $digits;
        } while (PaymentPlan::where('payment_reference', $code)->exists());

        return $code;
    }
}
