<?php

namespace App\Enums;

/**
 * Lifecycle status of a WeWire virtual account (App\Models\WeWireVirtualAccount).
 * Mirrors WeWire's own account status field — accounts progress REQUESTED -> PENDING ->
 * ACTIVE asynchronously (see `virtual_account.status_updated` webhook), or end up
 * DENIED/SUSPENDED/CLOSED.
 */
enum VirtualAccountStatus: string
{
    case REQUESTED = 'requested';
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case DENIED = 'denied';
    case SUSPENDED = 'suspended';
    case CLOSED = 'closed';
}
