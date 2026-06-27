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
}
