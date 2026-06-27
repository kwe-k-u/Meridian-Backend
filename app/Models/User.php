<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Sanctum\HasApiTokens;


/**
 * Model for the `users` table.
 *
 * Purpose: Represents an authenticated user account with Firebase authentication, capable of belonging to multiple companies.
 *
 * @property string $user_id Unique identifier for the user.
 * @property string|null $firebase_uid Firebase authentication UID.
 * @property UserStatus $status Account status (e.g., active, disabled).
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'firebase_uid',
        'email',
        'display_name',
        'phone',
        'avatar_url',
        'status',
        'last_login',
        'password'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'last_login' => 'datetime',

        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'user_companies', 'user_id', 'company_id')
            ->withPivot(['role', 'is_default', 'is_enabled', 'joined_at']);
    }
}
