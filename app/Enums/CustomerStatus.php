<?php

namespace App\Enums;

/**
 * Status of a customer (traveler) record. Defaults to ACTIVE on creation;
 * ARCHIVED is used instead of deleting when a company wants to keep history.
 */
enum CustomerStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case ARCHIVED = 'archived';
}
