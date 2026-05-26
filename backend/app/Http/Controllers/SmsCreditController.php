<?php

namespace App\Http\Controllers;

use App\Models\SmsCreditTransaction;
use App\Models\SystemSetting;
use App\Services\SmsCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SmsCreditController extends Controller
{
    public function summary(Request $request, SmsCreditService $credits)
    {
        $tenant = $request->user()->tenant;
        $wallet = $credits->wallet($tenant);

        return response()->json([
            'credits' => $wallet->credits,
            'credit_price' => (float) SystemSetting::get('sms_credit_price', 35),
            'currency' => (string) SystemSetting::get('tenant_subscription_currency', 'UGX'),
            'recent_transactions' => SmsCreditTransaction::where('tenant_id', $tenant->id)
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function topUp(Request $request, SmsCreditService $credits)
    {
        $validator = Validator::make($request->all(), [
            'msisdn' => ['required', 'string', 'regex:/^\+?(256|0)?(7[0-9]{8})$/'],
            'amount' => 'required|numeric|min:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $result = $credits->initiateTopUp($request->user()->tenant, $request->msisdn, (float) $request->amount);

        return response()->json($result, ($result['status'] ?? -1) === 1 ? 200 : 422);
    }

    public function paymentStatus(Request $request, SmsCreditService $credits)
    {
        $request->validate(['ref' => 'required|string']);

        return response()->json($credits->status($request->query('ref'), $request->user()->tenant_id));
    }

    public function ipn(Request $request, SmsCreditService $credits)
    {
        $processed = $credits->handleIpn($request->all());

        return response()->json(['processed' => $processed], $processed ? 200 : 400);
    }

    public function failure(Request $request, SmsCreditService $credits)
    {
        $processed = $credits->handleFailure($request->all());

        return response()->json(['processed' => $processed], $processed ? 200 : 400);
    }
}
