<?php

namespace App\Enums;

/**
 * Status of an emailed invitation to join a company (App\Models\Invitation).
 * The invitations table also has an `expires_at` timestamp; EXPIRED is a
 * settleable status once that timestamp has passed and the invite wasn't accepted.
 */
enum InvitationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';
}