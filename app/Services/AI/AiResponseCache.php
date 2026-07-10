<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;

class AiResponseCache
{
    private const TTL_SECONDS = 86400; // 24 h
    private const PREFIX = 'meridian_ai:';

    public function get(string $key): ?array
    {
        return Cache::get(self::PREFIX . $key);
    }

    public function put(string $key, array $data): void
    {
        Cache::put(self::PREFIX . $key, $data, self::TTL_SECONDS);
    }

    public function forget(string $key): void
    {
        Cache::forget(self::PREFIX . $key);
    }

    /**
     * Deterministic hash from the fields that uniquely identify a generation request.
     * Changes to any of these will bypass the cache and trigger a fresh LLM call.
     */
    public function makeKey(string $tripId, array $tripSpec, array $preferences): string
    {
        $payload = [
            'trip_id'       => $tripId,
            'trip_name'     => $tripSpec['trip_name'] ?? null,
            'start_date'    => $tripSpec['start_date'] ?? null,
            'end_date'      => $tripSpec['end_date'] ?? null,
            'budget_usd'    => $tripSpec['budget_usd'] ?? null,
            'traveler_count' => $tripSpec['traveler_count'] ?? 0,
            'preferences'   => $preferences,
        ];

        return md5(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
