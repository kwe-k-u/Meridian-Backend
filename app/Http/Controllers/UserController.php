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
 * Routes: /api/users (resourceful)
 */
class UserController extends Controller
{
    // GET /api/users — Returns paginated list of users with their companies.
    public function index(): JsonResponse
    {
        return response()->json(User::with('companies')->paginate(15));
    }

    // POST /api/users — Creates a new user.
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

    // GET /api/users/{user} — Returns a single user with their companies.
    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('companies'));
    }

    // PUT/PATCH /api/users/{user} — Updates a user's profile fields.
    public function update(Request $request, User $user): JsonResponse
    {
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

    // DELETE /api/users/{user} — Deletes a user.
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