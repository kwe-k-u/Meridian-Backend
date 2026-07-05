<?php

namespace App\Enums;

/**
 * Account status of a User. DISABLED users are blocked from logging in and from
 * resetting their password (see AuthController::issueSessionToken/sendResetLink).
 */
enum UserStatus: string
{
    case PENDING = 'pending';
    case ACTIVE = 'active';
    case DISABLED = 'disabled';
}