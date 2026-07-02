<?php

namespace App\Helpers;

use App\Models\Company;
use Illuminate\Http\Request;

class UserHelper
{
    /**
     * Get user company
     *
     * @return Company
     */
    public static function user_company(Request $request): Company
    {
        return $request->user()->active_company;
    }
}
