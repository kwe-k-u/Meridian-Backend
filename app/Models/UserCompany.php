<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\CompanyRole;


class UserCompany extends Model
{
    /** @use HasFactory<\Database\Factories\UserCompanyFactory> */
    use HasFactory;

    protected $table = 'user_companies';
    public $incrementing = false;
    public $timestamps = false; // Table uses joined_at instead of default timestamps

    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'is_default',
        'is_enabled',
        'joined_at',
    ];

    protected $casts = [
        'role' => CompanyRole::class,
        'is_default' => 'boolean',
        'is_enabled' => 'boolean',
        'joined_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id', 'company_id');
    }
}
