<?php

namespace App\Http\Controllers;

use App\Services\CurrencyService;
use App\Services\WeWireService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Exposes currency conversion rates (GHS per 1 unit of currency) to the frontend, so it can
 * convert and display amounts in whatever currency a company prefers.
 *
 * Rates come from WeWire's live Rates API (see App\Services\WeWireService::getPairRate —
 * https://docs.wewire.com/api-reference/rates/get-pair-rate), one pair-rate call per non-GHS
 * currency in App\Services\CurrencyService::RATES, cached for CACHE_TTL so the dashboard isn't
 * hitting WeWire on every load. If WeWire is unreachable, or fails for a given currency, that
 * currency silently falls back to CurrencyService's hardcoded snapshot rather than breaking the
 * whole response — so a WeWire outage degrades to the old (stale but consistent) numbers
 * instead of a broken dashboard.
 *
 * Routes: GET /api/currency-rates (public — no auth needed; this also lets the public traveler
 * view convert amounts, not just the authenticated app).
 */
class CurrencyController extends Controller
{
    private const CACHE_TTL = 900; // 15 minutes — rates don't move fast enough to justify a live call per request.
    private const CACHE_KEY = 'wewire_currency_rates';

    public function index(WeWireService $weWire): JsonResponse
    {
        $rates = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($weWire) {
            $rates = [];
            foreach (CurrencyService::supported() as $code) {
                $rates[$code] = $code === 'GHS' ? 1.0 : $this->livePairRate($weWire, $code);
            }
            return $rates;
        });

        return response()->json([
            'base' => 'GHS',
            'rates' => $rates,
        ]);
    }

    // GHS-per-1-unit rate for $code, from WeWire's bid/ask mid-price. Falls back to
    // CurrencyService's hardcoded snapshot on any failure (network error, bad/missing response).
    private function livePairRate(WeWireService $weWire, string $code): float
    {
        try {
            $pair = $weWire->getPairRate($code, 'GHS');
            if (isset($pair['bid'], $pair['ask'])) {
                return round((((float) $pair['bid']) + ((float) $pair['ask'])) / 2, 4);
            }
        } catch (\Throwable $e) {
            Log::warning("WeWire pair-rate lookup failed for {$code}->GHS, using fallback rate", ['error' => $e->getMessage()]);
        }

        return CurrencyService::RATES[$code];
    }
}
