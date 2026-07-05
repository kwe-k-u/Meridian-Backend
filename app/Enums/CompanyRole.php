<?php

namespace App\Enums;

/**
 * A user's role within a single company (stored on the user_companies pivot table).
 * OWNER is assigned automatically to whoever registers the company (AuthController::registerCompany).
 * Only these three values are valid — the frontend's "Roles" settings page must not invent others.
 */
enum CompanyRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';
}