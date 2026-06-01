<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Voucher;
use App\Support\SiteScope;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::with('voucher');
        $site = SiteScope::selectedSite($request);
        SiteScope::applyToTenantTable($query, 'transactions', $site, 'origin_site');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('origin_site')) {
            $query->where('origin_site', $request->origin_site);
        }

        if ($request->has('msisdn')) {
            $query->where('msisdn', 'LIKE', '%' . $request->msisdn . '%');
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json($transactions);
    }

    public function show($id)
    {
        $transaction = Transaction::with('voucher')->findOrFail($id);
        return response()->json($transaction);
    }

    public function statistics(Request $request)
    {
        $query = Transaction::query();
        $site = SiteScope::selectedSite($request);
        SiteScope::applyToTenantTable($query, 'transactions', $site, 'origin_site');

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $stats = [
            'total_transactions' => $query->count(),
            'successful_transactions' => (clone $query)->successful()->count(),
            'pending_transactions' => (clone $query)->pending()->count(),
            'failed_transactions' => (clone $query)->failed()->count(),
            'total_revenue' => (clone $query)->successful()->sum('amount'),
            'average_transaction_value' => (clone $query)->successful()->avg('amount'),
            'transactions_by_status' => (clone $query)->selectRaw('status, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('status')
                ->get(),
            'transactions_by_origin' => (clone $query)->selectRaw('origin_site, COUNT(*) as count, SUM(amount) as total')
                ->whereNotNull('origin_site')
                ->groupBy('origin_site')
                ->get(),
            'transactions_by_package' => (clone $query)->selectRaw('voucher_type, COUNT(*) as count, SUM(amount) as total')
                ->whereNotNull('voucher_type')
                ->groupBy('voucher_type')
                ->orderByDesc('total')
                ->get(),
            'repeat_customers' => (clone $query)->selectRaw('msisdn, COUNT(*) as purchases, SUM(amount) as total_spent')
                ->where('status', 'success')
                ->groupBy('msisdn')
                ->havingRaw('COUNT(*) > 1')
                ->orderByDesc('purchases')
                ->limit(10)
                ->get(),
        ];

        return response()->json($stats);
    }

    public function dailyReport(Request $request)
    {
        $days = $request->days ?? 30;

        $report = Transaction::selectRaw('DATE(created_at) as date, status, COUNT(*) as count, SUM(amount) as total')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date', 'status')
            ->orderBy('date', 'desc')
            ->get();

        return response()->json($report);
    }

    public function performanceAnalytics(Request $request)
    {
        $period = $request->query('period', 'today');
        [$start, $end, $bucket] = $this->resolvePerformancePeriod($period);
        $site = SiteScope::selectedSite($request);

        $transactionQuery = Transaction::query()
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end]);
        SiteScope::applyToTenantTable($transactionQuery, 'transactions', $site, 'origin_site');

        $voucherQuery = Voucher::query()
            ->whereNotNull('first_used_at')
            ->whereBetween('first_used_at', [$start, $end]);
        SiteScope::applyToTenantTable($voucherQuery, 'vouchers', $site);

        $mobileTotal = (clone $transactionQuery)->sum('amount');
        $mobileCount = (clone $transactionQuery)->count();
        $voucherTotal = (clone $voucherQuery)->sum('price');
        $voucherCount = (clone $voucherQuery)->count();

        $breakdown = $this->buildPerformanceBreakdown($transactionQuery, $voucherQuery, $start, $end, $bucket);

        $topVoucherTypes = (clone $voucherQuery)
            ->leftJoin('voucher_groups', 'vouchers.group_id', '=', 'voucher_groups.id')
            ->selectRaw('COALESCE(voucher_groups.group_name, vouchers.profile_name, "Voucher") as name, COUNT(vouchers.id) as sold, SUM(vouchers.price) as revenue')
            ->groupBy('name')
            ->orderByDesc('sold')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'sold' => (int) $row->sold,
                'revenue' => (float) $row->revenue,
            ]);

        return response()->json([
            'period' => $period,
            'bucket' => $bucket,
            'summary' => [
                'mobile_money_total' => (float) $mobileTotal,
                'mobile_money_transactions' => $mobileCount,
                'voucher_total' => (float) $voucherTotal,
                'vouchers_sold' => $voucherCount,
                'combined_total' => (float) $mobileTotal + (float) $voucherTotal,
            ],
            'breakdown' => $breakdown,
            'top_voucher_types' => $topVoucherTypes,
        ]);
    }

    private function resolvePerformancePeriod(string $period): array
    {
        $now = now();

        return match ($period) {
            'yesterday' => [$now->copy()->subDay()->subHours(11)->startOfHour(), $now->copy()->subDay()->endOfHour(), 'hour'],
            'week' => [$now->copy()->startOfWeek(), $now, 'day'],
            'month' => [$now->copy()->startOfMonth(), $now, 'day'],
            'three_months' => [$now->copy()->subMonths(3)->startOfDay(), $now, 'month'],
            'six_months' => [$now->copy()->subMonths(6)->startOfDay(), $now, 'month'],
            default => [$now->copy()->subHours(11)->startOfHour(), $now, 'hour'],
        };
    }

    private function buildPerformanceBreakdown($transactionQuery, $voucherQuery, $start, $end, string $bucket): array
    {
        $format = $bucket === 'hour' ? '%Y-%m-%d %H:00:00' : ($bucket === 'month' ? '%Y-%m-01' : '%Y-%m-%d');

        $mobileRows = (clone $transactionQuery)
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket_key, COUNT(*) as count, SUM(amount) as total")
            ->groupBy('bucket_key')
            ->get()
            ->keyBy('bucket_key');

        $voucherRows = (clone $voucherQuery)
            ->selectRaw("DATE_FORMAT(first_used_at, '{$format}') as bucket_key, COUNT(*) as count, SUM(price) as total")
            ->groupBy('bucket_key')
            ->get()
            ->keyBy('bucket_key');

        $rows = [];
        $cursor = $start->copy();

        while ($cursor <= $end) {
            $key = $bucket === 'hour'
                ? $cursor->format('Y-m-d H:00:00')
                : ($bucket === 'month' ? $cursor->format('Y-m-01') : $cursor->format('Y-m-d'));

            $mobile = $mobileRows->get($key);
            $voucher = $voucherRows->get($key);

            $rows[] = [
                'key' => $key,
                'label' => $bucket === 'hour'
                    ? $cursor->format('H:00')
                    : ($bucket === 'month' ? $cursor->format('M Y') : $cursor->format('d M')),
                'mobile_money_total' => (float) ($mobile->total ?? 0),
                'mobile_money_transactions' => (int) ($mobile->count ?? 0),
                'voucher_total' => (float) ($voucher->total ?? 0),
                'vouchers_sold' => (int) ($voucher->count ?? 0),
            ];

            $bucket === 'hour'
                ? $cursor->addHour()
                : ($bucket === 'month' ? $cursor->addMonthNoOverflow() : $cursor->addDay());
        }

        return $rows;
    }
}
