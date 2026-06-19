<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use App\Enums\UserStatus;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(User::with('companies')->paginate(15));
    }

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

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('companies'));
    }

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

    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        return response()->json(null, 204);
    }
}