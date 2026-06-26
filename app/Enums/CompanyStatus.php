<?php

namespace App\Enums;

enum CompanyStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
    case PAST_DUE = 'past_due';
}