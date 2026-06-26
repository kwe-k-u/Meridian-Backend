<?php

namespace App\Http\Controllers;

use App\Enums\TripCustomerRole;
use App\Enums\TripStatus;
use App\Models\Customer;
use App\Models\Trip;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class TripController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Trip::with(['company', 'createdBy'])->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|string|exists:companies,company_id',
            'created_by' => 'nullable|string|exists:users,user_id',
            'trip_name' => 'required|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget' => 'nullable|string|max:50',
            'status' => ['nullable', new Enum(TripStatus::class)],
        ]);

        $validated['trip_id'] = IdGeneratorService::generateId('TRP');
        $trip = Trip::create($validated);

        return response()->json($trip, 201);
    }

    public function show(Trip $trip): JsonResponse
    {
        return response()->json($trip->load([
            'company',
            'createdBy',
            'customers',
            'itineraries.itineraryDays.destinations',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'calls.actionItems',
            'tripPayments.transaction',
        ]));
    }

    public function update(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'trip_name' => 'sometimes|required|string|max:200',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'budget' => 'nullable|string|max:50',
            'status' => ['nullable', new Enum(TripStatus::class)],
        ]);

        $trip->update($validated);

        return response()->json($trip);
    }

    public function destroy(Trip $trip): JsonResponse
    {
        $trip->delete();
        return response()->json(null, 204);
    }

    public function addCustomer(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|string|exists:customers,customer_id',
            'role' => ['nullable', new Enum(TripCustomerRole::class)],
        ]);

        $trip->customers()->syncWithoutDetaching([
            $validated['customer_id'] => ['role' => $validated['role'] ?? 'primary'],
        ]);

        return response()->json($trip->load('customers'), 200);
    }

    public function removeCustomer(Trip $trip, Customer $customer): JsonResponse
    {
        $trip->customers()->detach($customer->customer_id);

        return response()->json(null, 204);
    }
}
