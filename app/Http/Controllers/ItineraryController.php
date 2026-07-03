<?php

namespace App\Http\Controllers;

use App\Enums\FlightStatus;
use App\Enums\AccommodationStatus;
use App\Enums\ItineraryStatus;
use App\Helpers\ItineraryHelper;
use App\Helpers\UserHelper;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryFlight;
use App\Models\ItineraryAccommodation;
use App\Models\ItineraryDayDestination;
use App\Models\Trip;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Manages trip itineraries including daily schedules, destinations, flights, and accommodation.
 *
 * Routes: /api/itineraries, /api/itineraries/{itinerary}/days, /api/itineraries/{itinerary}/flights, /api/itineraries/{itinerary}/accommodation
 */
class ItineraryController extends Controller
{
    // GET /api/itineraries — Returns paginated list of itineraries scoped to the user's companies.
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        return response()->json(
            Itinerary::with(['trip', 'createdBy'])
                ->where('trip_id', function ($q) use ($company) {
                    $q->select('trip_id')->from('trips')->where('company_id', $company->id);
                })
                ->paginate(15)
        );
    }

    // POST /api/itineraries — Creates a new itinerary for a trip (company-scoped).
    public function store(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $trip = Trip::where('company_id', $company->id)
            ->where('trip_id', $request->trip_id)
            ->first();

        if (!$trip) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'trip_id' => 'required|string|exists:trips,trip_id',
            'created_by' => 'nullable|string|exists:users,user_id',
            'itinerary_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $validated['itinerary_id'] = IdGeneratorService::generateId('ITN');
        $itinerary = Itinerary::create($validated);

        return response()->json($itinerary, 201);
    }

    // GET /api/itineraries/{itinerary} — Returns a single itinerary with days, destinations, flights, and accommodation (company-scoped).
    public function show(Request $request, Itinerary $itinerary): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $trip = Trip::where('company_id', $company->id)
            ->where('trip_id', $itinerary->trip_id)
            ->first();

        if (!$trip) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($itinerary->load([
            'trip',
            'createdBy',
            'itineraryDays.destinations',
            'itineraryFlights',
            'itineraryAccommodation',
        ]));
    }

    // PUT/PATCH /api/itineraries/{itinerary} — Updates itinerary details (company-scoped).
    public function update(Request $request, Itinerary $itinerary): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $trip = Trip::where('company_id', $company->id)
            ->where('trip_id', $itinerary->trip_id)
            ->first();

        if (!$trip) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'itinerary_name' => 'sometimes|required|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'status' => ['nullable', new Enum(ItineraryStatus::class)],
        ]);

        $itinerary->update($validated);

        return response()->json($itinerary);
    }

    // DELETE /api/itineraries/{itinerary} — Deletes an itinerary (company-scoped).
    public function destroy(Request $request, Itinerary $itinerary): JsonResponse
    {
        $company = UserHelper::user_company($request);
        $trip = Trip::where('company_id', $company->company_id)
            ->where('trip_id', $itinerary->trip_id)
            ->first();

        if (!$trip) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $itinerary->delete();
        return response()->json(null, 204);
    }

    // POST /api/itineraries/{itinerary}/days — Adds a day to an itinerary.
    public function addDay(Request $request, Itinerary $itinerary): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary($request, $itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'day_number' => 'required|integer|min:1',
            'date' => 'nullable|date',
            'title' => 'nullable|string|max:200',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:200',
        ]);

        $validated['itinerary_day_id'] = IdGeneratorService::generateId('ITD');
        $validated['itinerary_id'] = $itinerary->itinerary_id;
        $day = ItineraryDay::create($validated);

        return response()->json($day, 201);
    }

    // PUT/PATCH /api/itineraries/days/{itineraryDay} — Updates an itinerary day.
    public function updateDay(Request $request, ItineraryDay $itineraryDay): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary($request, $itineraryDay->itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'day_number' => 'sometimes|required|integer|min:1',
            'date' => 'nullable|date',
            'title' => 'nullable|string|max:200',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:200',
        ]);

        $itineraryDay->update($validated);

        return response()->json($itineraryDay);
    }

    // DELETE /api/itineraries/days/{itineraryDay} — Removes an itinerary day.
    public function removeDay(ItineraryDay $itineraryDay): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary(request(), $itineraryDay->itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $itineraryDay->delete();
        return response()->json(null, 204);
    }

    // POST /api/itineraries/days/{itineraryDay}/destinations — Attaches a destination to an itinerary day (upserts).
    public function addDestinationToDay(Request $request, ItineraryDay $itineraryDay): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary($request, $itineraryDay->itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'destination_id' => 'required|string|exists:destinations,destination_id',
            'cost' => 'nullable|string|max:50',
            'currency' => 'nullable|string|max:3',
            'activities' => 'nullable|string',
            'booking_url' => 'nullable|string|max:500',
        ]);

        ItineraryDayDestination::updateOrCreate(
            [
                'itinerary_day_id' => $itineraryDay->itinerary_day_id,
                'destination_id' => $validated['destination_id'],
            ],
            $validated
        );

        return response()->json($itineraryDay->load('destinations'), 200);
    }

    // DELETE /api/itineraries/days/{itineraryDay}/destinations/{destinationId} — Removes a destination from an itinerary day.
    public function removeDestinationFromDay(ItineraryDay $itineraryDay, string $destinationId): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary(request(), $itineraryDay->itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        ItineraryDayDestination::where('itinerary_day_id', $itineraryDay->itinerary_day_id)
            ->where('destination_id', $destinationId)
            ->delete();

        return response()->json(null, 204);
    }

    // POST /api/itineraries/{itinerary}/flights — Adds a flight booking to an itinerary.
    public function addFlight(Request $request, Itinerary $itinerary): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary($request, $itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'airline' => 'nullable|string|max:100',
            'flight_number' => 'nullable|string|max:20',
            'departure_airport' => 'nullable|string|max:100',
            'arrival_airport' => 'nullable|string|max:100',
            'departure_datetime' => 'nullable|date',
            'arrival_datetime' => 'nullable|date|after:departure_datetime',
            'cost' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|max:3',
            'booking_reference' => 'nullable|string|max:50',
            'booking_url' => 'nullable|string|max:500',
            'status' => ['nullable', new Enum(FlightStatus::class)],
        ]);

        $validated['flight_id'] = IdGeneratorService::generateId('FLT');
        $validated['itinerary_id'] = $itinerary->itinerary_id;
        $flight = ItineraryFlight::create($validated);

        return response()->json($flight, 201);
    }

    // PUT/PATCH /api/itineraries/flights/{itineraryFlight} — Updates a flight booking.
    public function updateFlight(Request $request, ItineraryFlight $itineraryFlight): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary($request, $itineraryFlight->itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'airline' => 'nullable|string|max:100',
            'flight_number' => 'nullable|string|max:20',
            'departure_airport' => 'nullable|string|max:100',
            'arrival_airport' => 'nullable|string|max:100',
            'departure_datetime' => 'nullable|date',
            'arrival_datetime' => 'nullable|date|after:departure_datetime',
            'cost' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|max:3',
            'booking_reference' => 'nullable|string|max:50',
            'booking_url' => 'nullable|string|max:500',
            'status' => ['nullable', new Enum(FlightStatus::class)],
        ]);

        $itineraryFlight->update($validated);

        return response()->json($itineraryFlight);
    }

    // DELETE /api/itineraries/flights/{itineraryFlight} — Removes a flight booking.
    public function removeFlight(ItineraryFlight $itineraryFlight): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary(request(), $itineraryFlight->itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $itineraryFlight->delete();
        return response()->json(null, 204);
    }

    // POST /api/itineraries/{itinerary}/accommodation — Adds accommodation to an itinerary.
    public function addAccommodation(Request $request, Itinerary $itinerary): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary($request, $itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'accommodation_name' => 'required|string|max:200',
            'address' => 'nullable|string|max:500',
            'check_in_date' => 'nullable|date',
            'check_out_date' => 'nullable|date|after:check_in_date',
            'room_type' => 'nullable|string|max:100',
            'cost' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|max:3',
            'booking_reference' => 'nullable|string|max:50',
            'booking_url' => 'nullable|string|max:500',
            'status' => ['nullable', new Enum(AccommodationStatus::class)],
        ]);

        $validated['accommodation_id'] = IdGeneratorService::generateId('ACC');
        $validated['itinerary_id'] = $itinerary->itinerary_id;
        $accommodation = ItineraryAccommodation::create($validated);

        return response()->json($accommodation, 201);
    }

    // PUT/PATCH /api/itineraries/accommodation/{itineraryAccommodation} — Updates accommodation details.
    public function updateAccommodation(Request $request, ItineraryAccommodation $itineraryAccommodation): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary($request, $itineraryAccommodation->itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'accommodation_name' => 'sometimes|required|string|max:200',
            'address' => 'nullable|string|max:500',
            'check_in_date' => 'nullable|date',
            'check_out_date' => 'nullable|date|after:check_in_date',
            'room_type' => 'nullable|string|max:100',
            'cost' => 'nullable|integer|min:0',
            'currency' => 'nullable|string|max:3',
            'booking_reference' => 'nullable|string|max:50',
            'booking_url' => 'nullable|string|max:500',
            'status' => ['nullable', new Enum(AccommodationStatus::class)],
        ]);

        $itineraryAccommodation->update($validated);

        return response()->json($itineraryAccommodation);
    }

    // DELETE /api/itineraries/accommodation/{itineraryAccommodation} — Removes accommodation from an itinerary.
    public function removeAccommodation(ItineraryAccommodation $itineraryAccommodation): JsonResponse
    {
        if (!ItineraryHelper::is_user_company_itinerary(request(), $itineraryAccommodation->itinerary)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $itineraryAccommodation->delete();
        return response()->json(null, 204);
    }
}
