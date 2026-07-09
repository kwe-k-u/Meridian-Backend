<?php

namespace App\Http\Controllers;

use App\Helpers\UserHelper;
use App\Models\Company;
use App\Services\CurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Handles CRUD operations for companies.
 *
 * Routes: /api/companies — only index/show/update are actually registered in routes/api.php
 * (see ->only(['index', 'show', 'update'])). store/destroy below exist but aren't reachable
 * over HTTP; companies are created via AuthController::registerCompany() instead.
 */
class CompanyController extends Controller
{
    // GET /api/companies — Returns the authenticated user's own active company (scoped via
    // UserHelper::user_company, same convention every other company-scoped controller uses).
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        return response()->json(Company::with('users')->where('company_id', $company->company_id)->paginate(15));
    }

    // Not routed — see class docblock. Companies are normally created via AuthController::registerCompany().
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

    // GET /api/companies/{company} — Returns a single company with its users (company-scoped:
    // only reachable for the caller's own active company).
    public function show(Request $request, Company $company): JsonResponse
    {
        if ($company->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($company->load('users'));
    }

    // PUT/PATCH /api/companies/{company} — Updates a company's name, city, or status
    // (company-scoped).
    public function update(Request $request, Company $company): JsonResponse
    {
        if ($company->company_id !== UserHelper::user_company($request)->company_id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'company_name' => 'sometimes|required|string|max:100',
            'city_of_operation' => 'nullable|string|max:50',
            'status' => 'sometimes|boolean',
            'preferred_currency' => ['sometimes', 'string', Rule::in(CurrencyService::supported())],
        ]);

        $company->update($validated);

        return response()->json($company);
    }

    // Not routed — see class docblock.
    public function destroy(Company $company): JsonResponse
    {
        $company->delete();
        return response()->json(null, 204);
    }
}