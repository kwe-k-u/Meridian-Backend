<?php

namespace App\Http\Controllers;

use App\Enums\AccommodationStatus;
use App\Enums\FlightStatus;
use App\Enums\ItineraryStatus;
use App\Enums\TripCustomerRole;
use App\Enums\TripStatus;
use App\Helpers\ItineraryHelper;
use App\Helpers\UserHelper;
use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\ItineraryAccommodation;
use App\Models\ItineraryDay;
use App\Models\ItineraryFlight;
use App\Models\Trip;
use App\Services\AI\AiResponseCache;
use App\Services\AI\ItineraryBuilderService;
use App\Services\AI\MeridianAiService;
use App\Services\AI\TripContextService;
use App\Models\Airport;
use App\Services\IdGeneratorService;
use App\Services\SerpApiService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Manages trips and their customer assignments, status transitions, and cost calculations.
 *
 * Routes: /api/trips (resourceful), /api/trips/{trip}/customers, /api/trips/{trip}/costs
 */
class TripController extends Controller
{
    // GET /api/trips — Returns paginated list of trips with company, creator, and customers.
    // 'customers' is required here (not just on show()) — the Trips list page's "Traveler"
    // column falls back to the creator's name whenever no customer is attached, so omitting
    // this relation made every trip look like it belonged to whoever created it.
    public function index(): JsonResponse
    {
        $trips = UserHelper::user_company(request())->trips()->with(['company', 'createdBy', 'customers'])->paginate(15);
        return response()->json($trips);
    }

    // POST /api/trips — Creates a new trip.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|string|exists:companies,company_id',
            'created_by' => 'nullable|string|exists:users,user_id',
            'trip_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget' => 'nullable|numeric|min:0',
            'status' => ['nullable', new Enum(TripStatus::class)],
        ]);

        $validated['trip_id'] = IdGeneratorService::generateId('TRP');
        $trip = Trip::create($validated);

        return response()->json($trip, 201);
    }

    // GET /api/trips/{trip} — Returns a single trip with all relations (company, customers, itineraries, calls, payments).
    public function show(Trip $trip): JsonResponse
    {
        if (!$trip) {
            return response()->json(['message' => 'Trip not found.'], 404);
        }

        if ($trip->company_id !== UserHelper::user_company(request())->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($trip->load([
            'company',
            'createdBy',
            'customers',
            'itineraries.itineraryDays.destinations.destination',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'calls.actionItems',
            'tripPayments.transaction',
        ]));
    }

    // PUT/PATCH /api/trips/{trip} — Updates trip details.
    public function update(Request $request, Trip $trip): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'trip_name' => 'sometimes|required|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget' => 'nullable|numeric|min:0',
            'status' => ['nullable', new Enum(TripStatus::class)],
        ]);

        $trip->update($validated);

        return response()->json($trip);
    }

    // DELETE /api/trips/{trip} — Deletes a trip.
    public function destroy(Trip $trip): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company(request())->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $trip->delete();
        return response()->json(null, 204);
    }

    // PUT /api/trips/{trip}/status — Updates a trip's status and returns the full trip with relations.
    public function updateStatus(Request $request, Trip $trip): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company(request())->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', new Enum(TripStatus::class)],
        ]);

        $trip->update($validated);

        return response()->json($trip->load([
            'company',
            'createdBy',
            'customers',
            'itineraries.itineraryDays.destinations.destination',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'calls.actionItems',
            'tripPayments.transaction',
        ]));
    }

    // GET /api/trips/{trip}/costs — Calculates and returns itinerary costs, payments, and outstanding balance.
    public function costs(Trip $trip): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company(request())->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $trip->load([
            'itineraries.itineraryDays.destinations.destination',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'tripPayments.transaction',
        ]);

        // Calculate costs for all itineraries
        $itineraryCosts = $trip->itineraries->map(fn($itin) => ItineraryHelper::calculateItineraryCost($itin));

        // Payments
        $payments = $trip->tripPayments->map(fn($tp) => [
            'transaction_id' => $tp->transaction_id,
            'amount' => (float) $tp->transaction->amount,
            'currency' => $tp->transaction->currency,
            'status' => $tp->transaction->status->value,
            'payment_method' => $tp->transaction->payment_method,
            'paid_at' => $tp->transaction->paid_at?->toIso8601String(),
            'notes' => $tp->notes,
        ])->values();

        $totalPaid = (float) $payments->where('status', 'completed')->sum('amount');
        $totalPending = (float) $payments->where('status', 'pending')->sum('amount');

        // Overall trip summary (sum of ALL itineraries' totals, not just the one currently
        // shown in the UI — the frontend picks a single entry out of `itineraries` by index
        // to display in the cost sidebar for whichever option is selected).
        $totalTripCost = $itineraryCosts->sum('total');

        return response()->json([
            'trip_id' => $trip->trip_id,
            'itineraries' => $itineraryCosts,
            'payments' => $payments,
            'summary' => [
                'total_cost' => $totalTripCost,
                'total_paid' => $totalPaid,
                'total_pending' => $totalPending,
                'outstanding' => round($totalTripCost - $totalPaid, 2),
            ],
        ]);
    }

    // POST /api/trips/{trip}/customers — Attaches a customer to a trip with an optional role.
    public function addCustomer(Request $request, Trip $trip): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company(request())->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $validated = $request->validate([
            'customer_id' => 'required|string|exists:customers,customer_id',
            'role' => ['nullable', new Enum(TripCustomerRole::class)],
        ]);

        $trip->customers()->syncWithoutDetaching([
            $validated['customer_id'] => ['role' => $validated['role'] ?? 'primary'],
        ]);

        return response()->json($trip->load('customers'), 200);
    }

    // DELETE /api/trips/{trip}/customers/{customer} — Detaches a customer from a trip.
    public function removeCustomer(Trip $trip, Customer $customer): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company(request())->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $trip->customers()->detach($customer->customer_id);

        return response()->json(null, 204);
    }

    // POST /api/trips/{trip}/generate-itinerary
    public function generateItinerary(Request $request, Trip $trip): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $preferences = $request->validate([
            'budget'       => 'nullable|string|max:50',
            'style'        => 'nullable|string|max:50',
            'priorities'   => 'nullable|array',
            'priorities.*' => 'string|max:50',
            'notes'        => 'nullable|string',
            'start_city'   => 'nullable|string|max:100',
            'provider'     => 'nullable|string|in:gemini,openai,anthropic,ollama',
        ]);

        $startDate = $trip->start_date ? Carbon::parse($trip->start_date) : Carbon::now()->addWeek();
        $endDate   = $trip->end_date   ? Carbon::parse($trip->end_date)   : $startDate->copy()->addDays(4);
        $startCity = $preferences['start_city'] ?? null;
        $userId    = $request->user()->user_id;

        // Delete all previous AI-generated (draft) itineraries so each run gives a fresh set.
        $trip->itineraries()->where('status', ItineraryStatus::DRAFT->value)->each(function (Itinerary $old) {
            $old->itineraryDays()->each(fn ($d) => $d->destinations()->delete());
            $old->itineraryDays()->delete();
            $old->itineraryFlights()->delete();
            $old->itineraryAccommodation()->delete();
            $old->delete();
        });

        // --- AI path ---
        $aiService = new MeridianAiService();

        if ($aiService->isReachable()) {
            $contextService = new TripContextService();
            $cache          = new AiResponseCache();
            $builder        = new ItineraryBuilderService();

            $context  = $contextService->build($trip, $preferences);
            $cacheKey = $cache->makeKey($trip->trip_id, $context['trip_spec'], $preferences);
            $aiResult = $cache->get($cacheKey);

            if (!$aiResult) {
                // --- SerpApi: fetch real flights & hotels to ground the AI's options ---
                $flightCandidates = [];
                $stayCandidates   = [];

                if (config('services.serpapi.key')) {
                    $serpApi = new SerpApiService();
                    $checkIn  = $startDate->toDateString();
                    $checkOut = $endDate->toDateString();
                    $guestCount = max(1, $trip->customers()->count());

                    // Hotel search — trip description often names the destination city directly
                    $hotelQuery = $trip->description ?? $trip->trip_name;
                    try {
                        $hotelResults   = $serpApi->searchHotels($hotelQuery, $checkIn, $checkOut, $guestCount);
                        $stayCandidates = array_slice($hotelResults['results'] ?? [], 0, 8);
                    } catch (\Throwable) {}

                    // Flight search — only run when we can resolve both departure and destination
                    // to IATA codes; SerpApi Google Flights requires 3-letter airport codes.
                    $departureCity = $startCity ?? null;
                    $destText      = $trip->description ?? $trip->trip_name ?? '';
                    $destCity      = self::extractDestinationCity($destText);
                    if ($departureCity && $destCity) {
                        $depIata  = self::cityToIata($departureCity);
                        $arrIata  = self::cityToIata($destCity);
                        if ($depIata && $arrIata) {
                            try {
                                $flightResults    = $serpApi->searchFlights($depIata, $arrIata, $checkIn, $checkOut);
                                $flightCandidates = array_slice($flightResults['results'] ?? [], 0, 6);
                            } catch (\Throwable) {}
                        }
                    }
                }

                // Ensure preferences serialises as a JSON object ({}) even when empty.
                $provider     = $preferences['provider'] ?? null;
                $prefsPayload = empty($preferences) ? new \stdClass() : $preferences;

                $aiPayload = [
                    'trip_brief'         => $context['trip_brief'],
                    'trip_spec'          => $context['trip_spec'],
                    'preferences'        => $prefsPayload,
                    'answered_questions' => [],
                    'flight_candidates'  => $flightCandidates,
                    'stay_candidates'    => $stayCandidates,
                    'num_options'        => 3,
                ];
                if ($provider) {
                    $aiPayload['provider'] = $provider;
                }

                $aiResult = $aiService->generateItinerary($aiPayload);
                $cache->put($cacheKey, $aiResult);
            }

            $itineraries = $builder->buildAll(
                $aiResult,
                $trip->trip_id,
                $userId,
                $startCity,
                $startDate,
                $endDate
            );

            $primary = $itineraries->first();
            return response()->json([
                'itinerary'   => $primary,
                'all_options' => $itineraries->values(),
            ], 201);
        }

        // --- Fallback when meridian-ai is offline: return a single blank draft ---
        $dayCount  = max(1, min(14, $startDate->diffInDays($endDate) + 1));
        $itinerary = Itinerary::create([
            'itinerary_id'   => IdGeneratorService::generateId('ITN'),
            'trip_id'        => $trip->trip_id,
            'created_by'     => $userId,
            'itinerary_name' => 'Draft itinerary',
            'start_city'     => $startCity,
            'description'    => 'AI service is currently offline. Fill in the details manually.',
            'start_date'     => $startDate->toDateString(),
            'end_date'       => $endDate->toDateString(),
            'status'         => ItineraryStatus::DRAFT->value,
        ]);

        $dayTitles = ['Arrival & check-in', 'City highlights', 'Excursion', 'Leisure', 'Culture & cuisine', 'Free day'];
        for ($i = 0; $i < $dayCount; $i++) {
            ItineraryDay::create([
                'itinerary_day_id' => IdGeneratorService::generateId('ITD'),
                'itinerary_id'     => $itinerary->itinerary_id,
                'day_number'       => $i + 1,
                'date'             => $startDate->copy()->addDays($i)->toDateString(),
                'title'            => $i === $dayCount - 1 && $dayCount > 1 ? 'Departure' : ($dayTitles[$i % count($dayTitles)]),
                'description'      => null,
            ]);
        }

        $loaded = $itinerary->load(['itineraryDays.destinations.destination', 'itineraryFlights', 'itineraryAccommodation']);
        return response()->json(['itinerary' => $loaded, 'all_options' => [$loaded]], 201);
    }

    // Extracts a plausible destination city from a trip description or name.
    // Used to determine the arrival airport for SerpApi flight search.
    private static function extractDestinationCity(string $text): ?string
    {
        // "in/to/at <City>" — most trip descriptions follow this pattern
        if (preg_match('/\b(?:in|to|at)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/u', $text, $m)) {
            return $m[1];
        }
        // First standalone proper noun (capitalised word after whitespace)
        if (preg_match('/(?<=\s)([A-Z][a-z]{2,})\b/', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    // Resolves a city name to its primary airport IATA code using the seeded airports table.
    // Prefers exact city-name matches and breaks ties by picking the alphabetically-first code
    // (major hub airports tend to sort first — ACC, JFK, LHR — over regional ones).
    private static function cityToIata(string $city): ?string
    {
        $airport = Airport::whereRaw('LOWER(city) LIKE ?', ['%' . strtolower(trim($city)) . '%'])
            ->orderByRaw('CASE WHEN LOWER(city) = ? THEN 0 ELSE 1 END', [strtolower(trim($city))])
            ->orderBy('iata_code')
            ->first(['iata_code']);

        return $airport?->iata_code;
    }
}
