<?php

namespace App\Enums;

/**
 * Lifecycle status of a WeWire crypto deposit wallet (App\Models\WeWireCryptoWallet). Mirrors
 * VirtualAccountStatus's REQUESTED -> ACTIVE progression (see the `subcustomer.wallet.created`
 * webhook), narrower since WeWire's wallet lifecycle doesn't have a PENDING/SUSPENDED step —
 * a request either activates or fails.
 */
enum CryptoWalletStatus: string
{
    case REQUESTED = 'requested';
    case ACTIVE = 'active';
    case FAILED = 'failed';
}
