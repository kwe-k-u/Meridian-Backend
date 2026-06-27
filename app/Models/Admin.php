<?php

namespace App\Models;

use App\Enums\AdminRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model for the `admins` table.
 *
 * Purpose: Tracks elevated administrative privileges granted to a user within the system.
 *
 * @property string $admin_id Unique identifier for the admin record.
 * @property string $user_id Foreign key to the associated user.
 * @property AdminRole $role The admin role (e.g., super_admin, support).
 */
class Admin extends Model
{
    use HasFactory;

    protected $table = 'admins';
    protected $primaryKey = 'admin_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false; // Schema explicitly defines only created_at

    protected $fillable = [
        'admin_id',
        'user_id',
        'role',
    ];

    protected $casts = [
        'role' => AdminRole::class,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}