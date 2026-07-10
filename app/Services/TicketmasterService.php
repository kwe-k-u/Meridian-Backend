<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Client for the Ticketmaster Discovery API v2.
 * Searches upcoming events at a destination (music, sports, arts, etc.) and
 * returns a normalised list the itinerary AI can use as event_candidates —
 * so generated itineraries reference real, bookable events rather than
 * generic placeholder activities.
 */
class TicketmasterService
{
    private string $baseUrl;
    private ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.ticketmaster.base_url', 'https://app.ticketmaster.com/discovery/v2'), '/');
        $this->apiKey  = config('services.ticketmaster.key');
    }

    /**
     * Search events at a destination within the trip date range.
     *
     * @param  string      $city            Destination city name (e.g. "Accra", "London")
     * @param  string      $startDate       ISO date — events from this date (YYYY-MM-DD)
     * @param  string      $endDate         ISO date — events up to this date (YYYY-MM-DD)
     * @param  string|null $classification  Optional filter: Music | Sports | Arts | Family | Film
     * @param  int         $size            Max results to return (capped at 20)
     * @return array{results: array, error?: string}
     */
    public function searchEvents(
        string $city,
        string $startDate,
        string $endDate,
        ?string $classification = null,
        int $size = 10,
    ): array {
        $params = [
            'apikey'        => $this->apiKey,
            'city'          => $city,
            'startDateTime' => $startDate . 'T00:00:00Z',
            'endDateTime'   => $endDate   . 'T23:59:59Z',
            'size'          => min($size, 20),
            'sort'          => 'date,asc',
        ];

        if ($classification) {
            $params['classificationName'] = $classification;
        }

        $response = Http::timeout(12)->get("{$this->baseUrl}/events.json", $params);

        if ($response->failed()) {
            return ['error' => $response->json('fault.faultstring') ?? 'Ticketmaster request failed.', 'results' => []];
        }

        $events = $response->json('_embedded.events') ?? [];

        $results = array_map(function (array $event) {
            $venue  = $event['_embedded']['venues'][0] ?? [];
            $prices = $event['priceRanges'][0]         ?? [];
            $class  = $event['classifications'][0]     ?? [];
            $image  = collect($event['images'] ?? [])
                ->sortByDesc('width')
                ->first();

            return [
                'name'            => $event['name'],
                'url'             => $event['url'] ?? null,
                'date'            => $event['dates']['start']['dateTime']
                                  ?? $event['dates']['start']['localDate']
                                  ?? null,
                'venue_name'      => $venue['name'] ?? null,
                'venue_city'      => $venue['city']['name'] ?? null,
                'venue_country'   => $venue['country']['name'] ?? null,
                'category'        => $class['segment']['name'] ?? null,
                'genre'           => $class['genre']['name'] ?? null,
                'price_min'       => $prices['min'] ?? null,
                'price_max'       => $prices['max'] ?? null,
                'currency'        => $prices['currency'] ?? 'USD',
                'image_url'       => $image['url'] ?? null,
            ];
        }, $events);

        return ['results' => $results];
    }
}
