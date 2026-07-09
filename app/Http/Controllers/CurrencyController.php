<?php

namespace App\Http\Controllers;

use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;

/**
 * Exposes the hardcoded currency conversion table (see App\Services\CurrencyService) to the
 * frontend, so it can convert and display amounts in whatever currency a company prefers.
 *
 * Routes: GET /api/currency-rates (public — static, non-sensitive data, so no auth needed;
 * this also lets the public traveler view convert amounts, not just the authenticated app).
 */
class CurrencyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'base' => 'GHS',
            'rates' => CurrencyService::RATES,
        ]);
    }
}
