<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model for the `user_companies` pivot table.
 *
 * Purpose: Links users to companies, storing their role, default status, and membership details.
 */
class UserCompany extends Model
{
    /** @use HasFactory<\Database\Factories\UserCompanyFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'is_default',
        'is_enabled',
        'joined_at',
    ];
}
