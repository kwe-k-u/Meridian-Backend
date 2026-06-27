<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use App\Enums\AdminRole;

/**
 * Handles CRUD operations for admin users with role management.
 *
 * Routes: /api/admins (resourceful)
 */
class AdminController extends Controller
{
    // GET /api/admins — Returns paginated list of admins with user relationship.
    public function index(): JsonResponse
    {
        return response()->json(Admin::with('user')->paginate(15));
    }

    // POST /api/admins — Creates a new admin record.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'admin_id' => 'required|string|max:20|unique:admins,admin_id',
            'user_id' => 'required|string|exists:users,user_id|unique:admins,user_id',
            'role' => ['nullable', new Enum(AdminRole::class)],
        ]);

        $admin = Admin::create($validated);

        return response()->json($admin, 201);
    }

    // GET /api/admins/{admin} — Returns a single admin with user relationship.
    public function show(Admin $admin): JsonResponse
    {
        return response()->json($admin->load('user'));
    }

    // PUT/PATCH /api/admins/{admin} — Updates the role of an existing admin.
    public function update(Request $request, Admin $admin): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['sometimes', 'required', new Enum(AdminRole::class)],
        ]);

        $admin->update($validated);

        return response()->json($admin);
    }

    // DELETE /api/admins/{admin} — Deletes an admin record.
    public function destroy(Admin $admin): JsonResponse
    {
        $admin->delete();
        return response()->json(null, 204);
    }
}