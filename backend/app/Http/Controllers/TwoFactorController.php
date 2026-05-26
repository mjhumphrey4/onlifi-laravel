<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TwoFactorController extends Controller
{
    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'enabled' => (bool) ($user->two_factor_enabled ?? false),
            'confirmed_at' => $user->two_factor_confirmed_at,
        ]);
    }

    public function setup(Request $request, TwoFactorService $twoFactor)
    {
        $user = $request->user();
        $secret = $twoFactor->generateSecret();

        $user->update([
            'two_factor_secret' => $twoFactor->encryptSecret($secret),
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ]);

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $twoFactor->otpauthUri('OnLiFi', $user->email, $secret),
            'message' => 'Scan or enter this secret in your authenticator app, then confirm with a 6-digit code.',
        ]);
    }

    public function confirm(Request $request, TwoFactorService $twoFactor)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json(['message' => 'Two-factor setup has not been started'], 400);
        }

        $secret = $twoFactor->decryptSecret($user->two_factor_secret);

        if (!$twoFactor->verifyCode($secret, $request->code)) {
            return response()->json(['message' => 'Invalid two-factor code'], 422);
        }

        $recoveryCodes = $twoFactor->generateRecoveryCodes();
        $user->update([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(array_map(fn ($code) => Hash::make($code), $recoveryCodes))),
        ]);

        return response()->json([
            'message' => 'Two-factor authentication enabled',
            'recovery_codes' => $recoveryCodes,
        ]);
    }

    public function disable(Request $request, TwoFactorService $twoFactor)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'nullable|string',
            'password' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        $passwordOk = $request->filled('password') && Hash::check($request->password, $user->password);
        $codeOk = false;

        if ($request->filled('code') && $user->two_factor_secret) {
            $codeOk = $twoFactor->verifyCode($twoFactor->decryptSecret($user->two_factor_secret), $request->code);
        }

        if (!$passwordOk && !$codeOk) {
            return response()->json(['message' => 'Provide your password or a valid two-factor code to disable 2FA'], 422);
        }

        $user->update([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        return response()->json(['message' => 'Two-factor authentication disabled']);
    }
}
