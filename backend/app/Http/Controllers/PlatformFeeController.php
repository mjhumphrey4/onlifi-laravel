<?php

namespace App\Http\Controllers;

use App\Models\PlatformFee;
use App\Models\PlatformRevenue;
use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlatformFeeController extends Controller
{
    /**
     * Get platform fee settings
     */
    public function getSettings()
    {
        return response()->json([
            'collection_fee_percent' => (float) SystemSetting::get('platform_collection_fee_percent', 5),
            'disbursement_fee_percent' => (float) SystemSetting::get('platform_disbursement_fee_percent', 2),
            'minimum_disbursement' => (float) SystemSetting::get('platform_minimum_disbursement', 10000),
        ]);
    }

    /**
     * Update platform fee settings
     */
    public function updateSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'collection_fee_percent' => 'sometimes|numeric|min:0|max:50',
            'disbursement_fee_percent' => 'sometimes|numeric|min:0|max:50',
            'minimum_disbursement' => 'sometimes|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($request->has('collection_fee_percent')) {
            SystemSetting::set(
                'platform_collection_fee_percent',
                $request->collection_fee_percent,
                'fees',
                'Percentage fee charged on each collection'
            );
        }

        if ($request->has('disbursement_fee_percent')) {
            SystemSetting::set(
                'platform_disbursement_fee_percent',
                $request->disbursement_fee_percent,
                'fees',
                'Percentage fee charged on each disbursement to tenant'
            );
        }

        if ($request->has('minimum_disbursement')) {
            SystemSetting::set(
                'platform_minimum_disbursement',
                $request->minimum_disbursement,
                'fees',
                'Minimum amount required for disbursement'
            );
        }

        return response()->json([
            'message' => 'Fee settings updated successfully',
            'settings' => $this->getSettings()->original,
        ]);
    }

    /**
     * Get platform revenue summary
     */
    public function getRevenueSummary(Request $request)
    {
        $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());

        $summary = PlatformRevenue::getSummary($startDate, $endDate);
        $today = PlatformRevenue::getToday();

        return response()->json([
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
            ],
            'summary' => $summary,
            'today' => $today ? [
                'total_collections' => $today->total_collections,
                'total_fees' => $today->total_fees,
                'transaction_count' => $today->transaction_count,
            ] : null,
            'all_time' => [
                'total_fees' => PlatformFee::getTotalPlatformFees(),
            ],
        ]);
    }

    /**
     * Get fee records with pagination
     */
    public function getFeeRecords(Request $request)
    {
        $query = PlatformFee::with('tenant:id,name,slug')
            ->orderBy('created_at', 'desc');

        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $fees = $query->paginate($request->query('per_page', 50));

        return response()->json($fees);
    }

    /**
     * Get tenant balances (for disbursement)
     */
    public function getTenantBalances()
    {
        $balances = PlatformFee::where('status', 'collected')
            ->selectRaw('
                tenant_id,
                SUM(net_amount) as balance,
                COUNT(*) as transaction_count,
                MAX(created_at) as last_transaction
            ')
            ->groupBy('tenant_id')
            ->with('tenant:id,name,slug')
            ->get();

        return response()->json([
            'balances' => $balances,
            'minimum_disbursement' => (float) SystemSetting::get('platform_minimum_disbursement', 10000),
        ]);
    }
}
