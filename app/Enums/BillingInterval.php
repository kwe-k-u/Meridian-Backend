<?php

namespace App\Enums;

enum BillingInterval: string
{
    case QUARTERLY = 'quarterly';
    case YEARLY = 'yearly';
}
