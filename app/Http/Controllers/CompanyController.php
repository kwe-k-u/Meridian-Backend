<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Company::with('users')->paginate(15));
    }

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

    public function show(Company $company): JsonResponse
    {
        return response()->json($company->load('users'));
    }

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

    public function destroy(Company $company): JsonResponse
    {
        $company->delete();
        return response()->json(null, 24);
    }
}