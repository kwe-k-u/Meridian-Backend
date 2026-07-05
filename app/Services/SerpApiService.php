<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin client for SerpApi's Google Flights and Google Hotels engines
 * (https://serpapi.com/search.json) — used by the itinerary builder to search and select
 * real flights/stays instead of typing them in by hand.
 *
 * Both methods return an already-normalized array (not the raw SerpApi payload, which is
 * large and carries a lot of fields — carbon emissions, nearby places, ad slots — the
 * frontend never needs). GHS is not an accepted SerpApi currency (`Unsupported 'GHS' for
 * currency` — confirmed by a live test call), so both searches are always run in USD; the
 * resulting ItineraryFlight/ItineraryAccommodation rows are saved with currency=USD to match
 * what was actually quoted, rather than silently mislabeling a USD price as GHS.
 */
class SerpApiService
{
    private const CURRENCY = 'USD';

    private string $baseUrl;
    private ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.serpapi.base_url');
        $this->apiKey = config('services.serpapi.key');
    }

    /**
     * Searches one-way or round-trip flights (engine=google_flights) and flattens
     * `best_flights` + `other_flights` into a single normalized list, each entry
     * representing one bookable itinerary (which may have multiple legs/layovers).
     */
    public function searchFlights(string $departureId, string $arrivalId, string $outboundDate, ?string $returnDate = null): array
    {
        $response = Http::get($this->baseUrl, [
            'engine' => 'google_flights',
            'api_key' => $this->apiKey,
            'departure_id' => strtoupper($departureId),
            'arrival_id' => strtoupper($arrivalId),
            'outbound_date' => $outboundDate,
            'return_date' => $returnDate,
            'type' => $returnDate ? 1 : 2, // 1 = round trip, 2 = one way
            'currency' => self::CURRENCY,
            'hl' => 'en',
            'gl' => 'us',
        ]);

        if ($response->failed()) {
            return ['error' => $response->json('error') ?? 'SerpApi request failed.', 'results' => []];
        }

        $body = $response->json();
        $combined = array_merge($body['best_flights'] ?? [], $body['other_flights'] ?? []);

        $results = array_map(function (array $itinerary, int $index) {
            $legs = array_map(fn($leg) => [
                'airline' => $leg['airline'] ?? 'Unknown',
                'airline_logo' => $leg['airline_logo'] ?? null,
                'flight_number' => $leg['flight_number'] ?? null,
                'departure_airport' => $leg['departure_airport']['id'] ?? null,
                'departure_airport_name' => $leg['departure_airport']['name'] ?? null,
                'departure_time' => $leg['departure_airport']['time'] ?? null,
                'arrival_airport' => $leg['arrival_airport']['id'] ?? null,
                'arrival_airport_name' => $leg['arrival_airport']['name'] ?? null,
                'arrival_time' => $leg['arrival_airport']['time'] ?? null,
                'duration' => $leg['duration'] ?? null,
                'airplane' => $leg['airplane'] ?? null,
            ], $itinerary['flights'] ?? []);

            return [
                'id' => (string) $index,
                'price' => $itinerary['price'] ?? null,
                'currency' => self::CURRENCY,
                'total_duration' => $itinerary['total_duration'] ?? null,
                'stops' => max(0, count($legs) - 1),
                'airline_logo' => $itinerary['airline_logo'] ?? null,
                'legs' => $legs,
            ];
        }, $combined, array_keys($combined));

        return [
            'currency' => self::CURRENCY,
            'google_flights_url' => $body['search_metadata']['google_flights_url'] ?? null,
            'results' => $results,
        ];
    }

    /**
     * Searches hotels/stays (engine=google_hotels) for a free-text query (typically a city
     * or destination name) across a date range, returning a flat, normalized property list.
     */
    public function searchHotels(string $query, string $checkInDate, string $checkOutDate, int $adults = 2): array
    {
        $response = Http::get($this->baseUrl, [
            'engine' => 'google_hotels',
            'api_key' => $this->apiKey,
            'q' => $query,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'adults' => $adults,
            'currency' => self::CURRENCY,
            'hl' => 'en',
            'gl' => 'us',
        ]);

        if ($response->failed()) {
            return ['error' => $response->json('error') ?? 'SerpApi request failed.', 'results' => []];
        }

        $body = $response->json();
        $properties = $body['properties'] ?? [];

        // Google Hotels' basic search never returns a street address (only GPS coordinates
        // and a list of named nearby places) — a formatted address requires a second,
        // per-property "details" call via property_token, which isn't worth the extra
        // round-trip here. address is left null; the property name + link are enough to
        // identify and book it.
        $results = array_map(fn($p) => [
            'property_token' => $p['property_token'] ?? null,
            'name' => $p['name'] ?? 'Unknown property',
            'link' => $p['link'] ?? null,
            'hotel_class' => $p['hotel_class'] ?? null,
            'overall_rating' => $p['overall_rating'] ?? null,
            'rate_per_night' => $p['rate_per_night']['extracted_lowest'] ?? null,
            'total_rate' => $p['total_rate']['extracted_lowest'] ?? null,
            'currency' => self::CURRENCY,
            'thumbnail' => $p['images'][0]['thumbnail'] ?? null,
            'gps_coordinates' => $p['gps_coordinates'] ?? null,
        ], $properties);

        return [
            'currency' => self::CURRENCY,
            'results' => $results,
        ];
    }
}
