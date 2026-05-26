<?php

namespace App\Http\Controllers;

use App\Services\SubscriptionBillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriptionBillingController extends Controller
{
    public function status(Request $request)
    {
        $tenant = $request->user()?->tenant;

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found',
            ], 404);
        }

        return response()->json([
            'billing' => $tenant->billingStatus(),
        ]);
    }

    public function subscribe(Request $request, SubscriptionBillingService $billing)
    {
        $validator = Validator::make($request->all(), [
            'msisdn' => ['required', 'string', 'regex:/^\+?(256|0)?(7[0-9]{8})$/'],
            'months' => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenant = $request->user()?->tenant;

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found',
            ], 404);
        }

        $msisdn = $this->normalizeMsisdn($request->string('msisdn')->toString());
        $result = $billing->initiate($tenant, $msisdn, (int) $request->input('months', 1));

        return response()->json($result, ($result['status'] ?? -1) === 1 ? 200 : 422);
    }

    public function paymentStatus(Request $request, SubscriptionBillingService $billing)
    {
        $validator = Validator::make($request->all(), [
            'ref' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenant = $request->user()?->tenant;
        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found',
            ], 404);
        }

        return response()->json($billing->status($request->query('ref'), $tenant->id));
    }

    public function ipn(Request $request, SubscriptionBillingService $billing)
    {
        $processed = $billing->handleIpn($request->all());

        return response()->json([
            'processed' => $processed,
        ], $processed ? 200 : 400);
    }

    public function failure(Request $request, SubscriptionBillingService $billing)
    {
        $processed = $billing->handleFailure($request->all());

        return response()->json([
            'processed' => $processed,
        ], $processed ? 200 : 400);
    }

    private function normalizeMsisdn(string $msisdn): string
    {
        $digits = preg_replace('/\D+/', '', $msisdn) ?? '';

        if (str_starts_with($digits, '0')) {
            return '256' . substr($digits, 1);
        }

        if (str_starts_with($digits, '7')) {
            return '256' . $digits;
        }

        return $digits;
    }
}
