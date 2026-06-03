<?php

namespace App\Http\Controllers;

use App\Models\SuperAdmin;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class SuperAdminAuthController extends Controller
{
    public function login(Request $request, TwoFactorService $twoFactor)
    {
        $validator = Validator::make($request->all(), [
            'login' => 'nullable|string',
            'email' => 'nullable|string',
            'password' => 'required|string',
            'two_factor_code' => 'nullable|string',
            'two_factor_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $identifier = trim((string) ($request->input('login') ?: $request->input('email')));
        if ($identifier === '') {
            return response()->json([
                'error' => 'Validation failed',
                'message' => 'Username or email is required',
                'errors' => ['login' => ['Username or email is required']],
            ], 422);
        }

        $admin = SuperAdmin::where('email', $identifier)
            ->orWhere('name', $identifier)
            ->first();

        if (!$admin || !Hash::check($request->password, $admin->password)) {
            return response()->json([
                'error' => 'Invalid credentials',
                'message' => 'Email or password is incorrect',
            ], 401);
        }

        if (!$admin->is_active) {
            return response()->json([
                'error' => 'Account inactive',
                'message' => 'Your account has been deactivated',
            ], 403);
        }

        if ($admin->two_factor_enabled) {
            if (!$request->filled('two_factor_code') || !$request->filled('two_factor_token')) {
                $pendingToken = Str::random(64);
                Cache::put("2fa:admin:{$pendingToken}", $admin->id, now()->addMinutes(5));

                return response()->json([
                    'requires_2fa' => true,
                    'two_factor_token' => $pendingToken,
                    'message' => 'Two-factor code required',
                ]);
            }

            $cachedAdminId = Cache::pull("2fa:admin:{$request->two_factor_token}");
            if ((int) $cachedAdminId !== (int) $admin->id) {
                return response()->json(['message' => 'Two-factor challenge expired'], 401);
            }

            $secret = $twoFactor->decryptSecret($admin->two_factor_secret);
            if (!$twoFactor->verifyCode($secret, $request->two_factor_code)) {
                return response()->json(['message' => 'Invalid two-factor code'], 401);
            }
        }

        if (!Schema::connection('central')->hasTable('personal_access_tokens')) {
            return response()->json([
                'error' => 'Authentication storage is not migrated',
                'message' => 'Run php artisan migrate --force on the backend server so the personal_access_tokens table is created.',
            ], 503);
        }

        $token = $admin->createToken('admin-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
            ],
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    public function me(Request $request)
    {
        $admin = $request->user();

        return response()->json([
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role,
                'is_active' => $admin->is_active,
                'created_at' => $admin->created_at,
            ],
        ]);
    }

    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $admin = $request->user();

        if (!Hash::check($request->current_password, $admin->password)) {
            return response()->json([
                'error' => 'Invalid password',
                'message' => 'Current password is incorrect',
            ], 401);
        }

        $admin->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }
}
