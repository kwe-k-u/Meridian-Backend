<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model for the `user_companies` pivot table.
 *
 * Purpose: Links users to companies, storing their role, default status, and membership details.
 * Not queried directly in normal request flow — User::companies()/Company::users() access the
 * same data as a plain array pivot via withPivot(), and User::active_company() uses this class
 * only as the intermediate model for its hasOneThrough() relation.
 *
 * @property string $user_id Foreign key to the user.
 * @property string $company_id Foreign key to the company.
 * @property string $role CompanyRole value (owner/admin/member) for this user in this company.
 * @property bool $is_default Whether this is the company shown by default in the UI switcher.
 * @property bool $is_enabled Whether this is the user's currently *active* company — see
 *                             User::active_company(), which every controller relies on for scoping.
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
