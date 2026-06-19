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
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\IdGeneratorService;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        $user = User::where('email', $validated['username'])->first();
        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'The credentials provided do not match our records.'
            ], 401);
        }

        if ($user->status === UserStatus::DISABLED) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Your account has been deactivated. Please contact support.'
            ], 403);
        }

        $user->update(['last_login' => now()]);
        $token = $user->createToken('meridian_auth_token')->plainTextToken;
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
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
                'city_of_operation' => $validated['country'],
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
