<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UserCompanyController extends Controller
{
    protected $table = 'user_companies';

    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'is_default',
        'is_enabled',
        'joined_at'
    ];
}
