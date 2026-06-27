<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Manages customer records scoped to the authenticated user's companies.
 *
 * Routes: /api/customers (resourceful, company-scoped)
 */
class CustomerController extends Controller
{
    // GET /api/customers — Returns paginated list of customers scoped to the user's companies.
    public function index(Request $request): JsonResponse
    {
        $companyIds = $request->user()->companies->pluck('company_id');

        return response()->json(
            Customer::with('company')
                ->whereIn('company_id', $companyIds)
                ->paginate(15)
        );
    }

    // POST /api/customers — Creates a new customer record within one of the user's companies.
    public function store(Request $request): JsonResponse
    {
        $companyIds = $request->user()->companies->pluck('company_id');

        $validated = $request->validate([
            'company_id' => 'required|string|exists:companies,company_id',
            'first_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'nationality' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'passport_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'status' => ['nullable', new Enum(CustomerStatus::class)],
        ]);

        if (!in_array($validated['company_id'], $companyIds->toArray())) {
            return response()->json(['message' => 'Unauthorized company.'], 403);
        }

        $validated['customer_id'] = IdGeneratorService::generateId('CST');
        $customer = Customer::create($validated);

        return response()->json($customer, 201);
    }

    // GET /api/customers/{customer} — Returns a single customer with company and trips (company-scoped).
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $companyIds = $request->user()->companies->pluck('company_id');

        if (!in_array($customer->company_id, $companyIds->toArray())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($customer->load(['company', 'trips']));
    }

    // PUT/PATCH /api/customers/{customer} — Updates customer details (company-scoped).
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $companyIds = $request->user()->companies->pluck('company_id');

        if (!in_array($customer->company_id, $companyIds->toArray())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:50',
            'last_name' => 'sometimes|required|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'nationality' => 'nullable|string|max:50',
            'date_of_birth' => 'nullable|date',
            'passport_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
            'status' => ['nullable', new Enum(CustomerStatus::class)],
        ]);

        $customer->update($validated);

        return response()->json($customer);
    }

    // DELETE /api/customers/{customer} — Deletes a customer record (company-scoped).
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $companyIds = $request->user()->companies->pluck('company_id');

        if (!in_array($customer->company_id, $companyIds->toArray())) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $customer->delete();
        return response()->json(null, 204);
    }
}
