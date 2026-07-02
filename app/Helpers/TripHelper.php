<?php

namespace App\Helpers;

use App\Models\Company;
use Illuminate\Http\Request;

class TripHelper
{
    /**
     * Get user company
     *
     * @return Company
     */
    public static function is_user_company_trip(Request $request): Company
    {
        return $request->user()->active_company;
    }
}
