<?php

namespace App\Models;

use App\Enums\CompanyRole;
use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `invitations` table.
 *
 * Purpose: Represents a pending invitation for a user to join a company with a specific role.
 *
 * @property string $token Primary key; unique token identifying the invitation.
 * @property string $company_id Foreign key to the inviting company.
 * @property string $invited_by Foreign key to the user who sent the invitation.
 * @property InvitationStatus $status Current status (e.g., pending, accepted, expired).
 * @property CompanyRole $role The role the invited user will receive upon acceptance.
 */
class Invitation extends Model
{
    use HasFactory;

    protected $table = 'invitations';
    protected $primaryKey = 'token';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'token',
        'company_id',
        'invited_by',
        'email',
        'role',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'role' => CompanyRole::class,
        'status' => InvitationStatus::class,
        'expires_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by', 'user_id');
    }
}