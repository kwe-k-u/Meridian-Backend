<?php

namespace App\Http\Controllers;

use App\Enums\TripCustomerRole;
use App\Enums\TripStatus;
use App\Helpers\UserHelper;
use App\Models\Customer;
use App\Models\Trip;
use App\Services\IdGeneratorService;
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
    // GET /api/trips — Returns paginated list of trips with company and creator.
    public function index(): JsonResponse
    {
        $trips = UserHelper::user_company(request())->trips()->with(['company', 'createdBy'])->paginate(15);
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
            'itineraries.itineraryDays.destinations',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'calls.actionItems',
            'tripPayments.transaction',
        ]));
    }

    // PUT/PATCH /api/trips/{trip} — Updates trip details.
    public function update(Request $request, Trip $trip): JsonResponse
    {
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
        $trip->delete();
        return response()->json(null, 204);
    }

    // PUT /api/trips/{trip}/status — Updates a trip's status and returns the full trip with relations.
    public function updateStatus(Request $request, Trip $trip): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', new Enum(TripStatus::class)],
        ]);

        $trip->update($validated);

        return response()->json($trip->load([
            'company', 'createdBy', 'customers',
            'itineraries.itineraryDays.destinations',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'calls.actionItems',
            'tripPayments.transaction',
        ]));
    }

    // GET /api/trips/{trip}/costs — Calculates and returns itinerary costs, payments, and outstanding balance.
    public function costs(Trip $trip): JsonResponse
    {
        $trip->load([
            'itineraries.itineraryDays.destinations',
            'itineraries.itineraryFlights',
            'itineraries.itineraryAccommodation',
            'tripPayments.transaction',
        ]);

        $itin = $trip->itineraries->first();

        $flightsCost = (float) ($itin?->itineraryFlights->sum('cost') ?? 0);
        $accommodationCost = (float) ($itin?->itineraryAccommodation->sum('cost') ?? 0);
        $activitiesCost = (float) ($itin?->itineraryDays->flatMap(fn($d) => $d->destinations)->sum('cost') ?? 0);

        $subtotal = $flightsCost + $accommodationCost + $activitiesCost;
        $serviceFee = round($subtotal * 0.05, 2);
        $total = $subtotal + $serviceFee;

        $currency = $itin?->itineraryFlights->first()?->currency
            ?? $itin?->itineraryAccommodation->first()?->currency
            ?? 'GHS';

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

        return response()->json([
            'trip_id' => $trip->trip_id,
            'currency' => $currency,
            'itinerary_costs' => [
                'flights' => $flightsCost,
                'accommodation' => $accommodationCost,
                'activities' => $activitiesCost,
                'subtotal' => $subtotal,
                'service_fee' => $serviceFee,
                'total' => $total,
            ],
            'payments' => $payments,
            'summary' => [
                'total_cost' => $total,
                'total_paid' => $totalPaid,
                'total_pending' => $totalPending,
                'outstanding' => round($total - $totalPaid, 2),
            ],
        ]);
    }

    // POST /api/trips/{trip}/customers — Attaches a customer to a trip with an optional role.
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

    // DELETE /api/trips/{trip}/customers/{customer} — Detaches a customer from a trip.
    public function removeCustomer(Trip $trip, Customer $customer): JsonResponse
    {
        $trip->customers()->detach($customer->customer_id);

        return response()->json(null, 204);
    }
}
