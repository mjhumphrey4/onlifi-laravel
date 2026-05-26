<?php

namespace App\Http\Controllers;

use App\Models\TenantUser;
use App\Models\Tenant;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class TenantAuthController extends Controller
{
    public function login(Request $request, TwoFactorService $twoFactor)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
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

        $user = TenantUser::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'error' => 'Invalid credentials',
                'message' => 'The provided email or password is incorrect',
            ], 401);
        }

        if (!$user->is_active) {
            return response()->json([
                'error' => 'Account disabled',
                'message' => 'Your account has been disabled. Please contact support.',
            ], 403);
        }

        // Check if tenant is active
        $tenant = $user->tenant;
        if (!$tenant || !$tenant->is_active) {
            return response()->json([
                'error' => 'Tenant inactive',
                'message' => 'Your organization account is not active. Please contact support.',
            ], 403);
        }

        if ($tenant->status !== 'approved') {
            return response()->json([
                'error' => 'Tenant not approved',
                'message' => 'Your organization account is pending approval.',
            ], 403);
        }

        if ($user->two_factor_enabled) {
            if (!$request->filled('two_factor_code') || !$request->filled('two_factor_token')) {
                $pendingToken = Str::random(64);
                Cache::put("2fa:tenant:{$pendingToken}", $user->id, now()->addMinutes(5));

                return response()->json([
                    'requires_2fa' => true,
                    'two_factor_token' => $pendingToken,
                    'message' => 'Two-factor code required',
                ]);
            }

            $cachedUserId = Cache::pull("2fa:tenant:{$request->two_factor_token}");
            if ((int) $cachedUserId !== (int) $user->id) {
                return response()->json(['message' => 'Two-factor challenge expired'], 401);
            }

            $secret = $twoFactor->decryptSecret($user->two_factor_secret);
            if (!$twoFactor->verifyCode($secret, $request->two_factor_code)) {
                return response()->json(['message' => 'Invalid two-factor code'], 401);
            }
        }

        // Create token
        $token = $user->createToken('tenant-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'tenant_slug' => $tenant->slug,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
            ],
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
        $user = $request->user();
        $tenant = $user->tenant;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'is_active' => $tenant->is_active,
                'settings' => $tenant->settings,
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

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'error' => 'Invalid password',
                'message' => 'Current password is incorrect',
            ], 400);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'message' => 'Password changed successfully',
        ]);
    }
}
