<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only lookup over the `airports` reference table (see Airport model / AirportSeeder).
 *
 * Routes: /api/airports/search
 */
class AirportController extends Controller
{
    // GET /api/airports/search?q= — Matches by IATA code, city, country, or airport name, so a
    // user can find an airport by typing a city or country instead of memorizing its code.
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $airports = Airport::where('iata_code', strtoupper($q))
            ->orWhere('city', 'like', "%{$q}%")
            ->orWhere('country', 'like', "%{$q}%")
            ->orWhere('name', 'like', "%{$q}%")
            ->orderBy('city')
            ->limit(8)
            ->get();

        return response()->json(['data' => $airports]);
    }
}
