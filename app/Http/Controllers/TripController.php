<?php

namespace App\Http\Controllers;

use App\Enums\AccommodationStatus;
use App\Enums\FlightStatus;
use App\Enums\ItineraryStatus;
use App\Enums\TripCustomerRole;
use App\Enums\TripStatus;
use App\Helpers\ItineraryHelper;
use App\Helpers\UserHelper;
use App\Mail\ItineraryAcceptedCustomerMail;
use App\Mail\ItineraryAcceptedMail;
use App\Mail\TripStatusChangedMail;
use App\Models\Customer;
use App\Models\Itinerary;
use App\Models\ItineraryAccommodation;
use App\Models\ItineraryDay;
use App\Models\ItineraryFlight;
use App\Models\Trip;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Services\AI\AiResponseCache;
use App\Services\AI\ItineraryBuilderService;
use App\Services\AI\MeridianAiService;
use App\Services\AI\TripContextService;
use App\Models\Airport;
use App\Services\BookingComService;
use App\Services\DefaultPaymentPlanService;
use App\Services\IdGeneratorService;
use App\Services\SerpApiService;
use App\Services\TicketmasterService;
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
    //
    // computed_total/computed_currency are added per trip so the list's "Value" column can show
    // the itinerary's actual cost (confirmed option if there is one, else the first) instead of
    // Trip.budget — a free-text estimate typed in at trip creation with no relationship to what
    // the itinerary actually costs, and always shown as GHS regardless of the itinerary's real
    // currency (see TripDetail.tsx's hero value, fixed the same way). Itinerary relations are
    // loaded only to compute these two fields and stripped back out before responding, so this
    // endpoint doesn't ship full itinerary detail (flights/accommodation/day-by-day) for every
    // row — show() already returns that for a single trip.
    public function index(): JsonResponse
    {
        $trips = UserHelper::user_company(request())->trips()->with([
            'company', 'createdBy', 'customers',
            'itineraries.itineraryFlights', 'itineraries.itineraryAccommodation', 'itineraries.itineraryDays.destinations',
        ])->paginate(15);

        $trips->getCollection()->transform(function (Trip $trip) {
            $itinerary = $trip->itineraries->firstWhere('status', ItineraryStatus::CONFIRMED) ?? $trip->itineraries->first();
            $cost = $itinerary ? ItineraryHelper::calculateItineraryCost($itinerary) : null;

            $data = $trip->toArray();
            $data['computed_total'] = $cost['total'] ?? null;
            $data['computed_currency'] = $cost['currency'] ?? null;
            unset($data['itineraries']);
            return $data;
        });

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

        $previousStatus = $trip->status?->value;
        $trip->update($validated);
        $statusChanged = $previousStatus !== $validated['status'];

        if ($statusChanged) {
            $trip->loadMissing(['company', 'customers']);
            foreach ($trip->customers as $customer) {
                if (!$customer->email) {
                    continue;
                }
                try {
                    Mail::to($customer->email)->send(new TripStatusChangedMail(
                        trim("{$customer->first_name} {$customer->last_name}") ?: 'Traveler',
                        $trip->trip_name,
                        $trip->company->company_name ?? 'Meridian',
                        $validated['status'],
                    ));
                } catch (Exception $e) {
                    Log::error('Failed to send trip status changed email', ['trip_id' => $trip->trip_id, 'customer_id' => $customer->customer_id, 'error' => $e->getMessage()]);
                }
            }
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

    // GET /api/trips/{trip}/costs — Calculates and returns itinerary costs, payments, and outstanding balance.
    public function costs(Trip $trip): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company(request())->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($this->buildCostsResponse($trip));
    }

    // GET /api/public/trips/{trip} — Public, read-only trip view for the shareable traveler
    // link (see TravelerView.tsx's /travel/:tripId route, deliberately outside the
    // authenticated /app/* section since travelers viewing their trip pack don't have
    // Meridian accounts). No company-ownership check — trip_id itself (an unguessable
    // generated ID) is the shared secret, same trust model the frontend route already uses.
    // Deliberately narrower than show(): no createdBy, calls, or tripPayments — nothing a
    // traveler doesn't need and nothing agency-internal.
    public function publicShow(Trip $trip): JsonResponse
    {
        // 'paymentPlans' added so the traveler-facing pay button (TravelerView.tsx) can find
        // the FULL/INSTALLMENTS default plans (see DefaultPaymentPlanService) by plan_type and
        // send the customer to /pay/{reference} for whichever they pick — WeWire has no hosted
        // checkout, so there's no "amount" to submit here, just a lookup key. Empty until the
        // agency has an accepted itinerary (that's what creates these) or a hand-built plan.
        return response()->json($trip->load([
            'company',
            'customers',
            'itineraries.itineraryDays.destinations.destination',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'paymentPlans',
        ]));
    }

    // POST /api/public/trips/{trip}/itineraries/{itinerary}/accept — Marks an itinerary option
    // as the one the traveler chose (CONFIRMED). Reachable from the same shareable /travel/{tripId}
    // link as publicShow() — trip_id/itinerary_id are the shared secret, same trust model.
    public function acceptItinerary(Trip $trip, Itinerary $itinerary): JsonResponse
    {
        if ($itinerary->trip_id !== $trip->trip_id) {
            return response()->json(['message' => 'Itinerary not found.'], 404);
        }

        $itinerary->update(['status' => ItineraryStatus::CONFIRMED->value]);

        // Auto-create the trip's default payment plans (a full lump-sum option and a
        // 3-installment option — see DefaultPaymentPlanService) now that its cost is settled.
        // Idempotent, so re-accepting (or accepting a different option later) never duplicates
        // them; a company that already hand-built a CUSTOM plan for this trip keeps it.
        $itinerary->loadMissing(['itineraryFlights', 'itineraryAccommodation', 'itineraryDays.destinations']);
        $cost = ItineraryHelper::calculateItineraryCost($itinerary);
        DefaultPaymentPlanService::createDefaults($trip, (float) $cost['total'], $cost['currency'], $trip->start_date);

        $trip->loadMissing(['company', 'createdBy', 'customers']);
        $customerName = trim($trip->customers->map(fn ($c) => trim("{$c->first_name} {$c->last_name}"))->filter()->first() ?? '') ?: 'The traveler';

        try {
            if ($trip->createdBy?->email) {
                Mail::to($trip->createdBy->email)->send(new ItineraryAcceptedMail(
                    $trip->createdBy->display_name ?? 'Team',
                    $itinerary->itinerary_name ?? 'Itinerary',
                    $trip->trip_name,
                    $customerName,
                ));
            }
        } catch (Exception $e) {
            Log::error('Failed to send itinerary accepted staff email', ['trip_id' => $trip->trip_id, 'itinerary_id' => $itinerary->itinerary_id, 'error' => $e->getMessage()]);
        }

        foreach ($trip->customers as $customer) {
            if (!$customer->email) {
                continue;
            }
            try {
                Mail::to($customer->email)->send(new ItineraryAcceptedCustomerMail(
                    trim("{$customer->first_name} {$customer->last_name}") ?: 'Traveler',
                    $itinerary->itinerary_name ?? 'Itinerary',
                    $trip->trip_name,
                    $trip->company->company_name ?? 'Meridian',
                ));
            } catch (Exception $e) {
                Log::error('Failed to send itinerary accepted customer email', ['trip_id' => $trip->trip_id, 'customer_id' => $customer->customer_id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json($itinerary->fresh());
    }

    // GET /api/public/trips/{trip}/costs — Public equivalent of costs(); same response shape
    // (including payment history), used by the traveler view to show what's been paid and
    // what's still outstanding, and to validate a custom payment amount before initiating one.
    public function publicCosts(Trip $trip): JsonResponse
    {
        return response()->json($this->buildCostsResponse($trip));
    }

    // Shared by costs()/publicCosts() — computes the itinerary cost breakdown, payment
    // history, and outstanding balance for a trip. Assumes no relations are pre-loaded.
    private function buildCostsResponse(Trip $trip): array
    {
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

        return [
            'trip_id' => $trip->trip_id,
            'itineraries' => $itineraryCosts,
            'payments' => $payments,
            'summary' => [
                'total_cost' => $totalTripCost,
                'total_paid' => $totalPaid,
                'total_pending' => $totalPending,
                'outstanding' => round($totalTripCost - $totalPaid, 2),
            ],
        ];
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
        // External API calls (flights, hotels, events) + AI generation can easily exceed 30 s.
        set_time_limit(300);

        if ($trip->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $preferences = $request->validate([
            'budget'         => 'nullable|string|max:50',
            'style'          => 'nullable|string|max:50',
            'priorities'     => 'nullable|array',
            'priorities.*'   => 'string|max:50',
            'notes'          => 'nullable|string',
            'start_city'     => 'nullable|string|max:100',
            'provider'       => 'nullable|string|in:gemini,openai,anthropic,ollama',
            // Specific model variant selected in the UI (e.g. "claude-sonnet-5", "gpt-4o").
            // Provider is inferred from the model prefix when not explicitly set.
            'model'                  => 'nullable|string|max:100',
            'include_flights'         => 'nullable|boolean',
            'include_stays'          => 'nullable|boolean',
            'include_events'         => 'nullable|boolean',
            // Legacy single-leg flight schedule hints (kept for backward compat).
            'flight_departure_time'  => 'nullable|string|max:20',
            'return_flight_time'     => 'nullable|string|max:20',
            // Multi-city / multi-leg flight schedule (supersedes the single-leg fields above).
            'flight_legs'            => 'nullable|array',
            'flight_legs.*.label'    => 'nullable|string|max:100',
            'flight_legs.*.date'     => 'nullable|string|max:20',
            'flight_legs.*.time'     => 'nullable|string|max:10',
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

            // Collect source URLs as the searches run so we can store them on each itinerary.
            $sourceLinks = [];

            if (!$aiResult) {
                // --- External APIs: enrich AI payload with real flights, hotels & events ---
                $flightCandidates = [];
                $stayCandidates   = [];
                $eventCandidates  = [];

                $checkIn    = $startDate->toDateString();
                $checkOut   = $endDate->toDateString();
                $guestCount = max(1, $trip->customers()->count());
                $destText   = $trip->description ?? $trip->trip_name ?? '';
                $destCity   = self::extractDestinationCity($destText);

                $includeFlights = (bool) ($preferences['include_flights'] ?? true);
                $includeStays   = (bool) ($preferences['include_stays']   ?? true);
                $includeEvents  = (bool) ($preferences['include_events']  ?? true);

                // Booking.com via RapidAPI: richer hotel data (stars, reviews, real pricing)
                if ($includeStays && config('services.hotels_rapidapi.key') && $destCity) {
                    try {
                        $bookingResults = (new BookingComService())->searchByCity($destCity, $checkIn, $checkOut, $guestCount, 8);
                        $stayCandidates = $bookingResults['results'] ?? [];
                        if (!empty($stayCandidates)) {
                            // Construct a Booking.com search URL for the agent to verify options.
                            $sourceLinks['hotels_url'] = 'https://www.booking.com/searchresults.html?'
                                . http_build_query(['ss' => $destCity, 'checkin' => $checkIn, 'checkout' => $checkOut, 'group_adults' => $guestCount]);
                        }
                    } catch (\Throwable) {}
                }

                // SerpApi: flights + fallback hotel search if Booking.com returned nothing
                if (config('services.serpapi.key')) {
                    $serpApi = new SerpApiService();

                    if ($includeFlights) {
                        $departureCity = $startCity ?? null;
                        if ($departureCity && $destCity) {
                            $depIata = self::cityToIata($departureCity);
                            $arrIata = self::cityToIata($destCity);
                            if ($depIata && $arrIata) {
                                try {
                                    $flightResults    = $serpApi->searchFlights($depIata, $arrIata, $checkIn, $checkOut);
                                    $flightCandidates = array_slice($flightResults['results'] ?? [], 0, 6);
                                    if ($flightResults['google_flights_url'] ?? null) {
                                        $sourceLinks['flights_url'] = $flightResults['google_flights_url'];
                                    }
                                } catch (\Throwable) {}
                            }
                        }
                    }

                    if ($includeStays && empty($stayCandidates) && $destCity) {
                        try {
                            $hotelResults   = $serpApi->searchHotels($destCity, $checkIn, $checkOut, $guestCount);
                            $stayCandidates = array_slice($hotelResults['results'] ?? [], 0, 8);
                            // Use first property link as the representative hotels source URL.
                            $firstLink = $stayCandidates[0]['link'] ?? null;
                            if ($firstLink) {
                                $sourceLinks['hotels_url'] = 'https://www.google.com/travel/hotels?q=' . urlencode($destCity);
                            }
                        } catch (\Throwable) {}
                    }
                }

                // Ticketmaster: real events at the destination
                if ($includeEvents && config('services.ticketmaster.key') && $destCity) {
                    try {
                        $eventResults    = (new TicketmasterService())->searchEvents($destCity, $checkIn, $checkOut, null, 10);
                        $eventCandidates = $eventResults['results'] ?? [];
                        if (!empty($eventCandidates)) {
                            $sourceLinks['events_url'] = 'https://www.ticketmaster.com/search?q=' . urlencode($destCity);
                        }
                    } catch (\Throwable) {}
                }

                // Resolve provider: explicit > inferred from model prefix > null (use AI service default).
                $selectedModel = $preferences['model'] ?? null;
                $provider      = $preferences['provider'] ?? self::modelToProvider($selectedModel);
                $prefsPayload  = empty($preferences) ? new \stdClass() : $preferences;

                $aiPayload = [
                    'trip_brief'         => $context['trip_brief'],
                    'trip_spec'          => $context['trip_spec'],
                    'preferences'        => $prefsPayload,
                    'answered_questions' => [],
                    'flight_candidates'  => $flightCandidates,
                    'stay_candidates'    => $stayCandidates,
                    'event_candidates'   => $eventCandidates,
                    'num_options'        => 3,
                ];
                if ($provider) {
                    $aiPayload['provider'] = $provider;
                }
                if ($selectedModel) {
                    $aiPayload['model'] = $selectedModel;
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
                $endDate,
                $sourceLinks ?: null
            );

            $primary = $itineraries->first();
            return response()->json([
                'itinerary'         => $primary,
                'all_options'       => $itineraries->values(),
                'provider_used'     => $aiResult['provider_used'] ?? null,
                'skipped_providers' => $aiResult['skipped_providers'] ?? [],
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

    // Maps a specific model ID (e.g. "claude-sonnet-5", "gpt-4o", "gemini-2.0-flash") to its
    // provider name understood by meridian-ai. Returns null if unrecognised (service uses default).
    private static function modelToProvider(?string $model): ?string
    {
        if (!$model) return null;
        if (str_starts_with($model, 'claude'))  return 'anthropic';
        if (str_starts_with($model, 'gpt'))     return 'openai';
        if (str_starts_with($model, 'gemini'))  return 'gemini';
        if (str_starts_with($model, 'llama') || str_starts_with($model, 'mistral')) return 'ollama';
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
