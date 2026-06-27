<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use App\Enums\CompanyRole;
use App\Enums\InvitationStatus;

/**
 * Manages company invitations sent to users by email.
 *
 * Routes: /api/invitations (resourceful)
 */
class InvitationController extends Controller
{
    // GET /api/invitations — Returns paginated list of invitations with company and inviter.
    public function index(): JsonResponse
    {
        return response()->json(Invitation::with(['company', 'inviter'])->paginate(15));
    }

    // POST /api/invitations — Creates a new invitation.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string|max:255|unique:invitations,token',
            'company_id' => 'required|string|exists:companies,company_id',
            'invited_by' => 'required|string|exists:users,user_id',
            'email' => 'required|email|max:255',
            'role' => ['nullable', new Enum(CompanyRole::class)],
            'status' => ['nullable', new Enum(InvitationStatus::class)],
            'expires_at' => 'required|date|after:now',
        ]);

        $invitation = Invitation::create($validated);

        return response()->json($invitation, 201);
    }

    // GET /api/invitations/{invitation} — Returns a single invitation with company and inviter.
    public function show(Invitation $invitation): JsonResponse
    {
        return response()->json($invitation->load(['company', 'inviter']));
    }

    // PUT/PATCH /api/invitations/{invitation} — Updates invitation status or role.
    public function update(Request $request, Invitation $invitation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', new Enum(InvitationStatus::class)],
            'role' => ['sometimes', 'required', new Enum(CompanyRole::class)],
        ]);

        $invitation->update($validated);

        return response()->json($invitation);
    }

    // DELETE /api/invitations/{invitation} — Deletes an invitation.
    public function destroy(Invitation $invitation): JsonResponse
    {
        $invitation->delete();
        return response()->json(null, 204);
    }
}