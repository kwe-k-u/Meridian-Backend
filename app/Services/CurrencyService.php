<?php

namespace App\Services;

/**
 * Fallback currency conversion table for the currencies used across the app (trip budgets,
 * itinerary flight/accommodation costs, transactions, and company display preference).
 *
 * Live rates now come from WeWire's Rates API (see App\Services\WeWireService::getPairRate,
 * used by App\Http\Controllers\CurrencyController) — RATES below is only used when that call
 * fails for a given currency (WeWire unreachable, bad response, etc). It's a snapshot of real
 * mid-market rates (GHS per 1 unit of the currency) pulled manually and needs refreshing by
 * hand as rates move. Last refreshed: 2026-07-09, from Wise/XE (~1 USD = 11.42 GHS,
 * ~1 EUR = 13.05 GHS, ~1 GBP = 15.26 GHS).
 */
class CurrencyService
{
    public const RATES = [
        'GHS' => 1.0,
        'USD' => 11.42,
        'EUR' => 13.05,
        'GBP' => 15.26,
    ];

    public static function supported(): array
    {
        return array_keys(self::RATES);
    }

    public static function isSupported(string $currency): bool
    {
        return array_key_exists($currency, self::RATES);
    }

    // Converts an amount from one supported currency to another via GHS as the pivot.
    // Unknown currencies are treated as 1:1 with GHS rather than throwing, so a bad/legacy
    // currency code on old data degrades gracefully instead of breaking the response.
    public static function convert(float $amount, string $from, string $to): float
    {
        if ($from === $to) {
            return $amount;
        }
        $fromRate = self::RATES[$from] ?? 1.0;
        $toRate = self::RATES[$to] ?? 1.0;
        return round($amount * $fromRate / $toRate, 2);
    }
}
