<?php

namespace App\Http\Controllers;

use App\Enums\CompanyRole;
use App\Models\User;
use App\Enums\UserStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use App\Models\Company;
use App\Services\IdGeneratorService;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        // 1. Check if this is a Google Sign-In attempt
        if ($request->has('provider_token')) {
            return $this->handleGoogleLogin($request);
        }

        // 2. Otherwise, fall back to standard Username/Password Validation
        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string|min:6',
        ]);

        $user = User::where('email', $validated['username'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['error' => 'Unauthorized', 'message' => 'Invalid credentials.'], 401);
        }

        return $this->issueSessionToken($user);
    }

    protected function handleGoogleLogin(Request $request): JsonResponse
    {
        $request->validate([
            'provider_token' => 'required|string',
        ]);

        try {            
            // Mocking decoding logic for simulation:
            $firebaseUid = 'fb_' . md5($request->provider_token);
            $email = $request->input('email');
            $displayName = $request->input('display_name');
            $avatarUrl = $request->input('avatar_url');

            $user = User::where('firebase_uid', $firebaseUid)
                        ->orWhere('email', $email)
                        ->first();

            if (!$user) {
                // Just-In-Time (JIT) Provisioning: Create the account seamlessly on first social login
                $userId = IdGeneratorService::generateId('USR');
                $user = User::create([
                    'user_id' => $userId,
                    'firebase_uid' => $firebaseUid,
                    'email' => $email,
                    'display_name' => $displayName,
                    'avatar_url' => $avatarUrl,
                    'status' => UserStatus::ACTIVE,
                    'last_login' => now(),
                    'password' => Hash::make(Str::random(32)), 
                ]);
            } else {
                // Link the Firebase UID if they originally registered via password but are now using Google
                if (is_null($user->firebase_uid)) {
                    $user->firebase_uid = $firebaseUid;
                }
                $user->last_login = now();
                $user->save();
            }

            return $this->issueSessionToken($user);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Google Authentication Failed',
                'message' => 'The token provided is invalid or expired.'
            ], 401);
        }
    }

    protected function issueSessionToken(User $user): JsonResponse
    {
        if ($user->status === UserStatus::DISABLED) {
            return response()->json(['error' => 'Forbidden', 'message' => 'Your account is deactivated.'], 403);
        }

        $token = $user->createToken('meridian_auth_token')->plainTextToken;
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user->load('companies'),
        ], 200);
    }

    public function registerCompany(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255|unique:users,email',
            'company_name' => 'required|string|max:100',
            'country' => 'required|string|max:100',
            'business_type' => 'required|string|max:50',
            'username' => 'required|string|max:50|unique:users,display_name',
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            DB::beginTransaction();

            $companyId = IdGeneratorService::generateId('CMP');
            $company = Company::create([
                'company_id' => $companyId,
                'company_name' => $validated['company_name'],
                'country' => $validated['country'],
                'status' => true,
            ]);

            $userId = IdGeneratorService::generateId('USR');
            $user = User::create([
                'user_id' => $userId,
                'email' => $validated['email'],
                'display_name' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'status' => UserStatus::ACTIVE,
                'last_login' => now(),
            ]);

            $company->users()->attach($user->user_id, [
                'role' => CompanyRole::OWNER->value,
                'is_default' => true,
                'is_enabled' => true,
                'joined_at' => now(),
            ]);

            DB::commit();

            $token = $user->createToken('meridian_auth_token')->plainTextToken;
            return response()->json([
                'message' => 'Company and owner registration completed successfully.',
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'company' => $company
            ], 201);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Registration Failed',
                'message' => 'An error occurred while provisioning your corporate workspace accounts. Please try again.',
            ], 500);
        }
    }

    public function sendResetLink(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = $request->input('email');

        $user = User::where('email', $email)->first();
        if (!$user) {
            return response()->json([
                'message' => 'If your email is registered in our database, you will receive a password reset link shortly.'
            ], 200);
        }

        if ($user->status === UserStatus::DISABLED) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'This account has been deactivated.'
            ], 403);
        }

        try {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            DB::table('password_reset_tokens')->insert([
                'email' => $email,
                'token' => bcrypt($token),
                'created_at' => now()
            ]);

            return response()->json([
                'message' => 'If your email is registered in our database, you will receive a password reset link shortly.',
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'error' => 'Server Error',
                'message' => 'Could not dispatch password reset link. Please try again later.',
            ], 500);
        }
    }

    public function resetForgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $email = $request->input('email');
        $token = $request->input('token');

        $resetRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (!$resetRecord || !Hash::check($token, $resetRecord->token)) {
            return response()->json([
                'error' => 'Invalid Token',
                'message' => 'This password reset link is invalid or has already been used.'
            ], 422);
        }

        $tokenExpirationMinutes = config('auth.passwords.users.expire', 30);
        if (Carbon::parse($resetRecord->created_at)->addMinutes($tokenExpirationMinutes)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return response()->json([
                'error' => 'Expired Token',
                'message' => 'This password reset link has expired. Please request a new one.'
            ], 422);
        }

        $user = User::where('email', $email)->first();
        $user->update([
            'password' => Hash::make($request->input('password'))
        ]);

        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        DB::table('password_reset_tokens')->where('email', $email)->delete();
        return response()->json([
            'message' => 'Your password has been successfully reset. You can now log in with your new credentials.'
        ], 200);
    }
}
