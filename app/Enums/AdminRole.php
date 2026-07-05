<?php

namespace App\Enums;

/**
 * Role of a platform Admin account (internal Meridian staff, distinct from CompanyRole
 * which is scoped to a single travel agency). Used by App\Models\Admin and AdminController.
 */
enum AdminRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case SUPPORT = 'support';
    case FINANCE = 'finance';
}