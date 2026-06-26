<?php

namespace App\Http\Controllers;

use App\Enums\FlightStatus;
use App\Enums\AccommodationStatus;
use App\Enums\ItineraryStatus;
use App\Models\Itinerary;
use App\Models\ItineraryDay;
use App\Models\ItineraryFlight;
use App\Models\ItineraryAccommodation;
use App\Models\ItineraryDayDestination;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class ItineraryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Itinerary::with(['trip', 'createdBy'])->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
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

    public function show(Itinerary $itinerary): JsonResponse
    {
        return response()->json($itinerary->load([
            'trip',
            'createdBy',
            'itineraryDays.destinations',
            'itineraryFlights',
            'itineraryAccommodation',
        ]));
    }

    public function update(Request $request, Itinerary $itinerary): JsonResponse
    {
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

    public function destroy(Itinerary $itinerary): JsonResponse
    {
        $itinerary->delete();
        return response()->json(null, 204);
    }

    // Itinerary Days
    public function addDay(Request $request, Itinerary $itinerary): JsonResponse
    {
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

    public function updateDay(Request $request, ItineraryDay $itineraryDay): JsonResponse
    {
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

    public function removeDay(ItineraryDay $itineraryDay): JsonResponse
    {
        $itineraryDay->delete();
        return response()->json(null, 204);
    }

    // Itinerary Day Destinations
    public function addDestinationToDay(Request $request, ItineraryDay $itineraryDay): JsonResponse
    {
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

    public function removeDestinationFromDay(ItineraryDay $itineraryDay, string $destinationId): JsonResponse
    {
        ItineraryDayDestination::where('itinerary_day_id', $itineraryDay->itinerary_day_id)
            ->where('destination_id', $destinationId)
            ->delete();

        return response()->json(null, 204);
    }

    // Itinerary Flights
    public function addFlight(Request $request, Itinerary $itinerary): JsonResponse
    {
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

    public function updateFlight(Request $request, ItineraryFlight $itineraryFlight): JsonResponse
    {
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

    public function removeFlight(ItineraryFlight $itineraryFlight): JsonResponse
    {
        $itineraryFlight->delete();
        return response()->json(null, 204);
    }

    // Itinerary Accommodation
    public function addAccommodation(Request $request, Itinerary $itinerary): JsonResponse
    {
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

    public function updateAccommodation(Request $request, ItineraryAccommodation $itineraryAccommodation): JsonResponse
    {
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

    public function removeAccommodation(ItineraryAccommodation $itineraryAccommodation): JsonResponse
    {
        $itineraryAccommodation->delete();
        return response()->json(null, 204);
    }
}
