<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Enums\UserStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;
use App\Enums\CompanyStatus;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * Handle standard username/email and password authentication.
     *
     * @param Request $request
     * @return JsonResponse
     */
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

        $user->update([
            'last_login' => now()
        ]);

        $token = $user->createToken('meridian_auth_token')->plainTextToken;
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 200);
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
}