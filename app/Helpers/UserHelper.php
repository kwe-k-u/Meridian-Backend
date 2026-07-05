<?php

namespace App\Helpers;

use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Central place almost every controller calls to figure out "which company is this
 * authenticated user acting as right now". Nearly all company-scoped queries (trips,
 * customers, itineraries, transactions, ...) filter by the company_id this returns.
 */
class UserHelper
{
    /**
     * Resolve the authenticated user's "active" company.
     *
     * A user can belong to multiple companies (see User::companies()), but only one
     * row in user_companies has is_enabled = true at a time — that's the one returned
     * here via User::active_company(). If a request needs to scope data to "my company",
     * this is the value to compare against.
     *
     * Aborts with a 403 JSON error if the user has no active company (e.g. a freshly
     * JIT-provisioned Google sign-in that was never attached to a company) — every
     * controller that calls this can rely on getting a real Company back, instead of
     * having to null-check and risk an uncaught TypeError crashing the request with a 500.
     *
     * @return Company
     */
    public static function user_company(Request $request): Company
    {
        $company = $request->user()->active_company;

        if (!$company) {
            abort(403, 'You do not have an active company.');
        }

        return $company;
    }
}
