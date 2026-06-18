<?php

namespace App\Enums;

enum AdminRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case SUPPORT = 'support';
    case FINANCE = 'finance';
}