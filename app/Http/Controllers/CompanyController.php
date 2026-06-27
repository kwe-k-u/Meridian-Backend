<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * Handles CRUD operations for companies.
 *
 * Routes: /api/companies (resourceful)
 */
class CompanyController extends Controller
{
    // GET /api/companies — Returns paginated list of companies with their users.
    public function index(): JsonResponse
    {
        return response()->json(Company::with('users')->paginate(15));
    }

    // POST /api/companies — Creates a new company.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => 'required|string|max:20|unique:companies,company_id',
            'company_name' => 'required|string|max:100',
            'city_of_operation' => 'nullable|string|max:50',
            'status' => 'nullable|boolean',
        ]);

        $company = Company::create($validated);

        return response()->json($company, 201);
    }

    // GET /api/companies/{company} — Returns a single company with its users.
    public function show(Company $company): JsonResponse
    {
        return response()->json($company->load('users'));
    }

    // PUT/PATCH /api/companies/{company} — Updates a company's name, city, or status.
    public function update(Request $request, Company $company): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => 'sometimes|required|string|max:100',
            'city_of_operation' => 'nullable|string|max:50',
            'status' => 'sometimes|boolean',
        ]);

        $company->update($validated);

        return response()->json($company);
    }

    // DELETE /api/companies/{company} — Deletes a company.
    public function destroy(Company $company): JsonResponse
    {
        $company->delete();
        return response()->json(null, 204);
    }
}