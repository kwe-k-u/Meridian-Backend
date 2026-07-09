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
use App\Services\IdGeneratorService;
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
            'budget' => 'nullable|numeric|decimal:2|max:50',
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
            'budget' => 'nullable|numeric|decimal:2|max:50',
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
        return response()->json($trip->load([
            'company',
            'customers',
            'itineraries.itineraryDays.destinations.destination',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
        ]));
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

    // POST /api/trips/{trip}/generate-itinerary — Simulates AI itinerary drafting: waits briefly, then
    // creates a templated itinerary (days, flights, accommodation) for the trip.
    public function generateItinerary(Request $request, Trip $trip): JsonResponse
    {
        if ($trip->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $preferences = $request->validate([
            'budget' => 'nullable|string|max:50',
            'style' => 'nullable|string|max:50',
            'priorities' => 'nullable|array',
            'priorities.*' => 'string|max:50',
            'notes' => 'nullable|string',
            'start_city' => 'nullable|string|max:100',
        ]);

        // Simulate AI processing time — this is a template generator, not a real AI call,
        // but the frontend shows a "Meridian is building options…" loading screen while it
        // waits, so we deliberately take a few seconds instead of responding instantly.
        sleep(4);

        // Use the trip's own dates if set, otherwise default to a 5-day trip starting next week.
        // Day count is clamped to [1, 14] so a bad/huge date range can't generate hundreds of rows.
        $startDate = $trip->start_date ? Carbon::parse($trip->start_date) : Carbon::now()->addWeek();
        $endDate = $trip->end_date ? Carbon::parse($trip->end_date) : $startDate->copy()->addDays(4);
        $dayCount = max(1, min(14, $startDate->diffInDays($endDate) + 1));

        // Name this itinerary the next unused letter (Option A, B, C...) based on how many
        // itineraries already exist for the trip, so re-generating creates a new option
        // rather than overwriting the previous one.
        $optionLetter = chr(65 + ($trip->itineraries()->count() % 26));

        // Fold any traveler preferences the frontend collected (GenerateItineraryModal) into
        // the itinerary description — purely cosmetic, doesn't change what gets generated.
        $descriptionParts = ['Auto-generated itinerary based on your trip brief.'];
        if (!empty($preferences['budget'])) {
            $descriptionParts[] = "Budget: {$preferences['budget']}.";
        }
        if (!empty($preferences['style'])) {
            $descriptionParts[] = "Style: {$preferences['style']}.";
        }
        if (!empty($preferences['priorities'])) {
            $descriptionParts[] = 'Priorities: ' . implode(', ', $preferences['priorities']) . '.';
        }

        $itinerary = Itinerary::create([
            'itinerary_id' => IdGeneratorService::generateId('ITN'),
            'trip_id' => $trip->trip_id,
            'created_by' => $request->user()->user_id,
            'itinerary_name' => "Option {$optionLetter}",
            'start_city' => $preferences['start_city'] ?? null,
            'description' => implode(' ', $descriptionParts),
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'status' => ItineraryStatus::DRAFT->value,
        ]);

        // A small rotating set of generic day plans. They cycle (via % count()) if the trip
        // is longer than the template list, and the very last day is always "Departure"
        // (unless the trip is only 1 day long, in which case there's nothing to cycle).
        $dayTemplates = [
            ['title' => 'Arrival & check-in', 'description' => 'Land, transfer to accommodation, and settle in.'],
            ['title' => 'City highlights & orientation', 'description' => 'Guided tour of the main sights and neighborhoods.'],
            ['title' => 'Full-day excursion', 'description' => 'A signature day trip or activity for the destination.'],
            ['title' => 'Leisure & optional activities', 'description' => 'Free time with optional add-ons.'],
            ['title' => 'Culture & cuisine', 'description' => 'Local food experience and a cultural site visit.'],
            ['title' => 'Free day', 'description' => 'Unstructured day to rest or explore independently.'],
        ];
        $departureTemplate = ['title' => 'Departure', 'description' => 'Check out and transfer to the airport.'];

        for ($i = 0; $i < $dayCount; $i++) {
            $isLastDay = $i === $dayCount - 1;
            $template = ($isLastDay && $dayCount > 1) ? $departureTemplate : $dayTemplates[$i % count($dayTemplates)];

            ItineraryDay::create([
                'itinerary_day_id' => IdGeneratorService::generateId('ITD'),
                'itinerary_id' => $itinerary->itinerary_id,
                'day_number' => $i + 1,
                'date' => $startDate->copy()->addDays($i)->toDateString(),
                'title' => $template['title'],
                'description' => $template['description'],
            ]);
        }

        // One outbound + one return flight, and a single accommodation booking spanning the
        // whole stay. Destination airport is always a placeholder ("TBD") since there's no
        // real flight search behind this template — but the departure/return-arrival side is
        // the traveler's own start_city when one was given, instead of also being "TBD".
        $originAirport = $preferences['start_city'] ?? 'TBD';
        ItineraryFlight::create([
            'flight_id' => IdGeneratorService::generateId('FLT'),
            'itinerary_id' => $itinerary->itinerary_id,
            'airline' => 'Meridian Air',
            'flight_number' => 'MA ' . random_int(100, 999),
            'departure_airport' => $originAirport,
            'arrival_airport' => 'TBD',
            'departure_datetime' => $startDate->copy()->setTime(8, 0)->toDateTimeString(),
            'arrival_datetime' => $startDate->copy()->setTime(14, 0)->toDateTimeString(),
            'cost' => 1200,
            'currency' => 'GHS',
            'status' => FlightStatus::PENDING->value,
        ]);

        ItineraryFlight::create([
            'flight_id' => IdGeneratorService::generateId('FLT'),
            'itinerary_id' => $itinerary->itinerary_id,
            'airline' => 'Meridian Air',
            'flight_number' => 'MA ' . random_int(100, 999),
            'departure_airport' => 'TBD',
            'arrival_airport' => $originAirport,
            'departure_datetime' => $endDate->copy()->setTime(16, 0)->toDateTimeString(),
            'arrival_datetime' => $endDate->copy()->setTime(22, 0)->toDateTimeString(),
            'cost' => 1200,
            'currency' => 'GHS',
            'status' => FlightStatus::PENDING->value,
        ]);

        ItineraryAccommodation::create([
            'accommodation_id' => IdGeneratorService::generateId('ACC'),
            'itinerary_id' => $itinerary->itinerary_id,
            'accommodation_name' => 'Recommended stay',
            'check_in_date' => $startDate->toDateString(),
            'check_out_date' => $endDate->toDateString(),
            'room_type' => 'Standard room',
            'cost' => 400 * max(1, $dayCount - 1), // ~400 GHS/night, nights = days - 1 (min 1 night).
            'currency' => 'GHS',
            'status' => AccommodationStatus::PENDING->value,
        ]);

        return response()->json($itinerary->load([
            'itineraryDays.destinations.destination',
            'itineraryFlights',
            'itineraryAccommodation',
        ]), 201);
    }
}
