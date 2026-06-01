<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Services\RadiusService;
use App\Services\VoucherAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RadiusController extends Controller
{
    private $radiusService;
    private $voucherAccountingService;

    public function __construct(RadiusService $radiusService, VoucherAccountingService $voucherAccountingService)
    {
        $this->radiusService = $radiusService;
        $this->voucherAccountingService = $voucherAccountingService;
    }

    /**
     * Sync all active vouchers to RADIUS
     */
    public function syncAllVouchers(Request $request)
    {
        $result = $this->radiusService->syncAllActiveVouchers();

        return response()->json([
            'message' => 'Voucher sync completed',
            'total' => $result['total'],
            'synced' => $result['synced'],
            'failed' => $result['failed'],
        ]);
    }

    /**
     * Sync a single voucher to RADIUS
     */
    public function syncVoucher(Request $request, $id)
    {
        $voucher = Voucher::find($id);
        
        if (!$voucher) {
            return response()->json(['error' => 'Voucher not found'], 404);
        }

        $success = $this->radiusService->syncVoucher($voucher);

        if ($success) {
            return response()->json([
                'message' => 'Voucher synced to RADIUS successfully',
                'voucher_code' => $voucher->voucher_code,
            ]);
        }

        return response()->json([
            'error' => 'Failed to sync voucher to RADIUS',
        ], 500);
    }

    /**
     * Cleanup expired vouchers from RADIUS
     */
    public function cleanupExpired(Request $request)
    {
        $result = $this->voucherAccountingService->cleanupCurrentTenantDatabase(true);

        return response()->json([
            'message' => 'Cleanup completed',
            'reconciled' => $result['reconciled'],
            'expired' => $result['expired'],
            'kicked' => $result['kicked'],
            'failed' => $result['failed'],
        ]);
    }

    /**
     * Get session history for a voucher
     */
    public function getSessions(Request $request, $voucher_code)
    {
        $activeSessions = $this->radiusService->getActiveSessions($voucher_code);
        $sessionHistory = $this->radiusService->getSessionHistory($voucher_code);

        return response()->json([
            'voucher_code' => $voucher_code,
            'active_sessions' => $activeSessions,
            'session_history' => $sessionHistory,
        ]);
    }

    /**
     * Authenticate a voucher (for testing)
     */
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

        $voucher = Voucher::where('voucher_code', $request->username)
            ->where('password', $request->password)
            ->whereIn('status', ['unused', 'reserved', 'in_use'])
            ->first();

        if ($voucher) {
            return response()->json([
                'authenticated' => true,
                'username' => $request->username,
                'voucher' => [
                    'validity_hours' => $voucher->validity_hours,
                    'data_limit_mb' => $voucher->data_limit_mb,
                    'speed_limit_kbps' => $voucher->speed_limit_kbps,
                    'status' => $voucher->status,
                ],
            ]);
        }

        return response()->json([
            'authenticated' => false,
            'error' => 'Invalid credentials',
        ], 401);
    }
}
