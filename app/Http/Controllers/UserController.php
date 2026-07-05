<?php

namespace App\Http\Controllers;

use App\Enums\CompanyRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use App\Enums\UserStatus;
use App\Helpers\UserHelper;
use App\Models\UserCompany;

/**
 * Handles CRUD operations for user accounts.
 *
 * Routes: /api/users — only index/show/update are actually registered in routes/api.php
 * (see ->only(['index', 'show', 'update'])). store/destroy below exist but aren't reachable
 * over HTTP; users are normally created via AuthController (registerCompany / Google JIT).
 */
class UserController extends Controller
{
    // GET /api/users — Returns paginated list of users who share the caller's active company
    // (scoped the same way every other company-scoped controller is).
    public function index(Request $request): JsonResponse
    {
        $company = UserHelper::user_company($request);
        return response()->json(
            User::with('companies')
                ->whereHas('companies', fn($q) => $q->where('companies.company_id', $company->company_id))
                ->paginate(15)
        );
    }

    // Not routed — see class docblock. Users are normally created via AuthController.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => 'required|string|max:20|unique:users,user_id',
            'firebase_uid' => 'nullable|string|max:128|unique:users,firebase_uid',
            'email' => 'required|email|max:255|unique:users,email',
            'display_name' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'avatar_url' => 'nullable|url|max:500',
            'status' => ['nullable', new Enum(UserStatus::class)],
        ]);

        $user = User::create($validated);

        return response()->json($user, 201);
    }

    // GET /api/users/{user} — Returns a single user with their companies (only if they share
    // the caller's active company).
    public function show(Request $request, User $user): JsonResponse
    {
        if (!$this->sharesActiveCompany($request, $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($user->load('companies'));
    }

    // PUT/PATCH /api/users/{user} — Updates a user's profile fields (only if they share the
    // caller's active company).
    public function update(Request $request, User $user): JsonResponse
    {
        if (!$this->sharesActiveCompany($request, $user)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'firebase_uid' => 'sometimes|nullable|string|max:128|unique:users,firebase_uid,' . $user->user_id . ',user_id',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $user->user_id . ',user_id',
            'display_name' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'avatar_url' => 'nullable|url|max:500',
            'status' => ['sometimes', new Enum(UserStatus::class)],
        ]);

        $user->update($validated);

        return response()->json($user);
    }

    // Whether $user belongs to the same active company as the authenticated caller.
    private function sharesActiveCompany(Request $request, User $user): bool
    {
        $company = UserHelper::user_company($request);
        return $user->companies()->where('companies.company_id', $company->company_id)->exists();
    }

    // Not routed — see class docblock.
    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        return response()->json(null, 204);
    }

    public function updateStatus(Request $request) {
        $validated = $request->validate([
            'user_id' => 'string|required|max:20'
        ]);

        $user = User::find($validated['user_id']);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $company = UserHelper::user_company($request);
        if (!$company->company_id == $user->active_company->company_id) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($user->status == UserStatus::ACTIVE->value) {
            $user->status = UserStatus::DISABLED;
        } else {
            $user->status = UserStatus::ACTIVE;
        }

        $user->save();
        return response()->json($user, 201);
    }

    public function updateRole(Request $request) {
        $validated = $request->validate([
            'user_id' => 'string|required|max:20',
            'role' => ['required', new Enum(CompanyRole::class)]
        ]);
        $user = User::find($validated['user_id']);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $company = UserHelper::user_company($request);
        if (!$company->company_id == $user->active_company->company_id) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user_company = UserCompany::where('user_id', $user->user_id)
                                    ->where('is_enabled', true)
                                    ->where('company_id', $company->company_id)
                                    ->first();
        if (!$user_company) {
            return response()->json(['message' => 'User not found'], 404);
        }
        $user_company->role = $validated['role'];
        $user_company->save();
        return response()->json([
            'user' => $user,
            'role' => $user_company->role
            ], 201);
    }
}