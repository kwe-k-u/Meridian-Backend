<?php

namespace App\Http\Controllers;

use App\Enums\CompanyRole;
use App\Enums\InvitationStatus;
use App\Enums\UserStatus;
use App\Mail\DemoInvitationMail;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Services\IdGeneratorService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Lets an authenticated admin invite someone to a seat on the shared demo account
 * (see database/seeders/DemoDataSeeder.php) via a unique emailed link. No seat cap —
 * the invitee just supplies their name on accept and is provisioned + logged straight in,
 * the same JIT pattern AuthController::handleGoogleLogin() uses for passwordless accounts.
 *
 * Routes: POST /api/demo-invites (authenticated), GET/POST /api/public/demo-invites/{token} (public)
 */
class DemoInviteController extends Controller
{
    // POST /api/demo-invites — Sends a unique join link to the invitee's email.
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $demoCompany = $this->demoCompany();
        if (!$demoCompany) {
            return response()->json(['error' => 'Server Error', 'message' => 'Demo account is not configured.'], 500);
        }

        $invitation = Invitation::create([
            'token' => Str::random(64),
            'company_id' => $demoCompany->company_id,
            'invited_by' => $request->user()->user_id,
            'email' => $validated['email'],
            'role' => CompanyRole::MEMBER->value,
            'status' => InvitationStatus::PENDING->value,
            'expires_at' => now()->addDays((int) config('services.demo.invite_expiry_days', 30)),
        ]);

        $joinUrl = rtrim(config('services.wewire.frontend_url'), '/') . '/demo/join?token=' . urlencode($invitation->token);

        try {
            Mail::to($invitation->email)->send(new DemoInvitationMail($request->user()->display_name, $joinUrl));
        } catch (Exception $e) {
            Log::error('Failed to send demo invitation email', ['token' => $invitation->token, 'error' => $e->getMessage()]);
        }

        return response()->json($invitation, 201);
    }

    // GET /api/public/demo-invites/{token} — Lets the join landing page validate a link (and
    // show a clear "expired"/"already used" state) before asking the invitee for their name.
    public function show(string $token): JsonResponse
    {
        $invitation = Invitation::find($token);
        if (!$invitation) {
            return response()->json(['message' => 'This invitation link is invalid.'], 404);
        }

        return response()->json([
            'email' => $invitation->email,
            'status' => $this->settledStatus($invitation)->value,
        ]);
    }

    // POST /api/public/demo-invites/{token} — Accepts the invite: provisions (or reuses) a
    // User for the invitation's email, seats them on the demo company, and logs them in.
    public function accept(Request $request, string $inviteToken): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $invitation = Invitation::find($inviteToken);
        if (!$invitation) {
            return response()->json(['message' => 'This invitation link is invalid.'], 404);
        }

        $status = $this->settledStatus($invitation);
        if ($status !== InvitationStatus::PENDING) {
            return response()->json(['message' => 'This invitation link has already been used or has expired.'], 422);
        }

        $user = User::where('email', $invitation->email)->first();
        if (!$user) {
            $user = User::create([
                'user_id' => IdGeneratorService::generateId('USR'),
                'email' => $invitation->email,
                'display_name' => $validated['name'],
                // Passwordless account, same as Google JIT provisioning — they sign back in
                // via a fresh invite link, not a password, so fill this with an unusable value.
                'password' => Hash::make(Str::random(32)),
                'status' => UserStatus::ACTIVE,
                'last_login' => now(),
            ]);
        }

        $demoCompany = $invitation->company;
        if (!$demoCompany->users()->where('users.user_id', $user->user_id)->exists()) {
            $demoCompany->users()->attach($user->user_id, [
                'role' => $invitation->role->value,
                'is_default' => $user->companies()->count() === 0,
                'is_enabled' => true,
                'joined_at' => now(),
            ]);
        }

        $invitation->update(['status' => InvitationStatus::ACCEPTED->value]);

        $authToken = $user->createToken('meridian_auth_token')->plainTextToken;
        return response()->json([
            'access_token' => $authToken,
            'token_type' => 'Bearer',
            'user' => $user->load('companies'),
        ]);
    }

    // An invitation whose expires_at has passed but is still stored as PENDING is
    // functionally expired — settle it here rather than relying on a scheduled job.
    private function settledStatus(Invitation $invitation): InvitationStatus
    {
        if ($invitation->status === InvitationStatus::PENDING && $invitation->expires_at->isPast()) {
            $invitation->update(['status' => InvitationStatus::EXPIRED->value]);
            return InvitationStatus::EXPIRED;
        }

        return $invitation->status;
    }

    private function demoCompany(): ?Company
    {
        $demoUser = User::where('email', config('services.demo.account_email', 'demo@meridian.com'))->first();
        return $demoUser?->companies()->first();
    }
}
