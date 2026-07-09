<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Client for the Booking.com hotel search API via RapidAPI.
 * Returns real hotel listings (pricing, stars, reviews) as stay_candidates
 * for AI-powered itinerary generation — richer data than SerpApi's Google Hotels.
 *
 * Flow: searchDestination() first to resolve a city to a dest_id, then
 * searchHotels() with that id. Both calls are combined in searchByCity()
 * so callers only need to pass a city name.
 */
class BookingComService
{
    private string $host;
    private ?string $apiKey;

    public function __construct()
    {
        $this->host   = config('services.hotels_rapidapi.host', 'booking-com15.p.rapidapi.com');
        $this->apiKey = config('services.hotels_rapidapi.key');
    }

    /**
     * Searches hotels in a city for a given date range.
     * Resolves the city name to a Booking.com dest_id first, then fetches listings.
     *
     * @return array{results: array, error?: string}
     */
    public function searchByCity(
        string $city,
        string $checkIn,
        string $checkOut,
        int $adults = 2,
        int $limit = 10,
    ): array {
        $destId = $this->resolveDestId($city);
        if (!$destId) {
            return ['results' => [], 'error' => "Could not resolve destination for: {$city}"];
        }

        return $this->searchHotels($destId, $checkIn, $checkOut, $adults, $limit);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function resolveDestId(string $city): ?string
    {
        $response = Http::withHeaders([
            'X-RapidAPI-Key'  => $this->apiKey,
            'X-RapidAPI-Host' => $this->host,
        ])->get("https://{$this->host}/api/v1/hotels/searchDestination", ['query' => $city]);

        if ($response->failed()) {
            return null;
        }

        // Prefer an exact city-type match; fall back to the first result
        $destinations = $response->json('data') ?? [];
        $cityMatch = collect($destinations)->firstWhere('dest_type', 'city');

        return ($cityMatch ?? $destinations[0] ?? null)['dest_id'] ?? null;
    }

    private function searchHotels(
        string $destId,
        string $checkIn,
        string $checkOut,
        int $adults,
        int $limit,
    ): array {
        $response = Http::withHeaders([
            'X-RapidAPI-Key'  => $this->apiKey,
            'X-RapidAPI-Host' => $this->host,
        ])->get("https://{$this->host}/api/v1/hotels/searchHotels", [
            'dest_id'       => $destId,
            'search_type'   => 'city',
            'arrival_date'  => $checkIn,
            'departure_date'=> $checkOut,
            'adults'        => $adults,
            'room_qty'      => 1,
            'currency_code' => 'USD',
            'languagecode'  => 'en-us',
            'page_number'   => 1,
        ]);

        if ($response->failed()) {
            return ['results' => [], 'error' => 'Booking.com hotel search failed.'];
        }

        $hotels = $response->json('data.hotels') ?? [];

        $results = array_slice(array_map(function (array $hotel) {
            $prop  = $hotel['property'] ?? [];
            $price = $prop['priceBreakdown']['grossPrice'] ?? [];

            return [
                'name'         => $prop['name'] ?? 'Unknown hotel',
                'stars'        => $prop['propertyClass'] ?? null,
                'review_score' => $prop['reviewScore'] ?? null,
                'review_count' => $prop['reviewCount'] ?? null,
                'price_total'  => isset($price['value']) ? round((float) $price['value'], 2) : null,
                'currency'     => $price['currency'] ?? 'USD',
                'check_in'     => $prop['checkinDate'] ?? null,
                'check_out'    => $prop['checkoutDate'] ?? null,
                'photo_url'    => $prop['photoUrls'][0] ?? null,
                'source'       => 'booking.com',
            ];
        }, $hotels), 0, $limit);

        return ['results' => $results];
    }
}
