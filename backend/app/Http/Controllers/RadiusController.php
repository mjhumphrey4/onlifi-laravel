<?php

namespace App\Http\Controllers;

use App\Services\FreeRadiusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RadiusController extends Controller
{
    private $radiusService;

    public function __construct(FreeRadiusService $radiusService)
    {
        $this->radiusService = $radiusService;
    }

    public function authenticate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'authenticated' => false,
                'error' => 'Invalid credentials format',
            ], 400);
        }

        $authenticated = $this->radiusService->checkRadiusAuth(
            $request->username,
            $request->password
        );

        if ($authenticated) {
            $limits = $this->radiusService->getRadiusSessionLimits($request->username);

            return response()->json([
                'authenticated' => true,
                'username' => $request->username,
                'session_limits' => $limits,
            ]);
        }

        return response()->json([
            'authenticated' => false,
            'error' => 'Invalid credentials',
        ], 401);
    }

    public function syncVoucher(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'voucher_code' => 'required|string',
            'password' => 'required|string',
            'validity_hours' => 'required|integer',
            'data_limit_mb' => 'nullable|integer',
            'speed_limit_kbps' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $success = $this->radiusService->syncVoucherToRadius($request->all());

        if ($success) {
            return response()->json([
                'message' => 'Voucher synced to FreeRADIUS successfully',
            ]);
        }

        return response()->json([
            'error' => 'Failed to sync voucher to FreeRADIUS',
        ], 500);
    }

    public function removeVoucher(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $success = $this->radiusService->removeVoucherFromRadius($request->username);

        if ($success) {
            return response()->json([
                'message' => 'Voucher removed from FreeRADIUS successfully',
            ]);
        }

        return response()->json([
            'error' => 'Failed to remove voucher from FreeRADIUS',
        ], 500);
    }
}
