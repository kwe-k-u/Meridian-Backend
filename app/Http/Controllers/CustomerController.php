<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\IdGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Customer::with('company')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
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

        $validated['customer_id'] = IdGeneratorService::generateId('CST');
        $customer = Customer::create($validated);

        return response()->json($customer, 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        return response()->json($customer->load(['company', 'trips']));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
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

    public function destroy(Customer $customer): JsonResponse
    {
        $customer->delete();
        return response()->json(null, 204);
    }
}
