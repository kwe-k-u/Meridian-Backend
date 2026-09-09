<?php

namespace App\Enums;

/**
 * How a WeWire virtual account (App\Models\WeWireVirtualAccount) treats funds once a
 * payment lands: HOLD keeps them in the Meridian/WeWire wallet, DISBURSE automatically pays
 * them out to the account's linked beneficiary. HOLD is the default for every new account.
 */
enum FundHandling: string
{
    case HOLD = 'hold';
    case DISBURSE = 'disburse';
}
