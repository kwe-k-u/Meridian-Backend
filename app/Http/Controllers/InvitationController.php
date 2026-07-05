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
 * Routes: /api/invitations — only POST (store) is actually registered in routes/api.php.
 * index/show/update/destroy below exist but aren't reachable over HTTP yet (no way to list,
 * view, accept/decline, or revoke an invitation from the API — just create one).
 */
class InvitationController extends Controller
{
    // Not routed — see class docblock.
    public function index(): JsonResponse
    {
        return response()->json(Invitation::with(['company', 'inviter'])->paginate(15));
    }

    // POST /api/invitations — Creates a new invitation. The frontend (Settings > Team page)
    // generates `token` and `expires_at` itself and sends them in the request body — see
    // ApiService.sendInvitation() — rather than this endpoint generating them server-side.
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

    // Not routed — see class docblock.
    public function show(Invitation $invitation): JsonResponse
    {
        return response()->json($invitation->load(['company', 'inviter']));
    }

    // Not routed — see class docblock. Would be how an invite gets marked accepted/cancelled.
    public function update(Request $request, Invitation $invitation): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'required', new Enum(InvitationStatus::class)],
            'role' => ['sometimes', 'required', new Enum(CompanyRole::class)],
        ]);

        $invitation->update($validated);

        return response()->json($invitation);
    }

    // Not routed — see class docblock.
    public function destroy(Invitation $invitation): JsonResponse
    {
        $invitation->delete();
        return response()->json(null, 204);
    }
}