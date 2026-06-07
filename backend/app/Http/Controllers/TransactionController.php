<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\PlatformFee;
use App\Models\Voucher;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::with('voucher');
        $site = SiteScope::selectedSite($request);
        $this->applyTransactionSiteScope($query, $site);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('origin_site')) {
            $query->where('origin_site', $request->origin_site);
        }

        if ($request->has('msisdn')) {
            $query->where('msisdn', 'LIKE', '%' . $request->msisdn . '%');
        }

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('id', 'LIKE', $search)
                    ->orWhere('msisdn', 'LIKE', $search)
                    ->orWhere('external_ref', 'LIKE', $search)
                    ->orWhere('transaction_ref', 'LIKE', $search)
                    ->orWhere('voucher_code', 'LIKE', $search)
                    ->orWhere('client_mac', 'LIKE', $search)
                    ->orWhere('status', 'LIKE', $search);
            });
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        $this->attachFeeDetails($transactions);

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
        $this->applyTransactionSiteScope($query, $site);
        $tenantId = app()->bound('tenant') ? app('tenant')->id : 'unknown';
        $cacheKey = sprintf(
            'tenant:%s:site:%s:transactions:statistics:%s:%s:v2',
            $tenantId,
            $site?->id ?: 'default',
            $request->query('from_date', 'none'),
            $request->query('to_date', 'none')
        );

        if (!$request->boolean('refresh') && ($cached = Cache::get($cacheKey))) {
            $cached['cache'] = ['source' => 'redis', 'ttl_seconds' => 300];
            return response()->json($cached);
        }

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

        $stats['cache'] = ['source' => 'database', 'ttl_seconds' => 300];
        Cache::put($cacheKey, $stats, now()->addMinutes(5));

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
        $tenantId = app()->bound('tenant') ? app('tenant')->id : 'unknown';
        $cacheKey = sprintf(
            'tenant:%s:site:%s:transactions:performance:%s:v2',
            $tenantId,
            $site?->id ?: 'default',
            $period
        );

        if (!$request->boolean('refresh') && ($cached = Cache::get($cacheKey))) {
            $cached['cache'] = ['source' => 'redis', 'ttl_seconds' => 300];
            return response()->json($cached);
        }

        $transactionQuery = Transaction::query()
            ->where('status', 'success')
            ->whereBetween('created_at', [$start, $end]);
        $this->applyTransactionSiteScope($transactionQuery, $site);

        $voucherQuery = Voucher::query()
            ->whereNotNull('first_used_at')
            ->whereBetween('first_used_at', [$start, $end]);
        SiteScope::applyToTenantTable($voucherQuery, 'vouchers', $site);
        $this->hideManualPaymentVouchers($voucherQuery);

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

        $voucherRowsQuery = (clone $voucherQuery)->leftJoin('voucher_groups', 'vouchers.group_id', '=', 'voucher_groups.id');
        $voucherRows = $voucherRowsQuery
            ->selectRaw('
                vouchers.id,
                vouchers.voucher_code,
                vouchers.status,
                vouchers.price,
                vouchers.used_by_mac,
                vouchers.used_by_ip,
                vouchers.first_used_at,
                vouchers.last_used_at,
                vouchers.expires_at,
                COALESCE(voucher_groups.group_name, vouchers.profile_name, "Voucher") as voucher_type
            ')
            ->orderByDesc('vouchers.first_used_at')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'voucher_code' => $row->voucher_code,
                'voucher_type' => $row->voucher_type,
                'status' => $row->status,
                'price' => (float) $row->price,
                'mac_address' => $row->used_by_mac,
                'ip_address' => $row->used_by_ip,
                'first_used_at' => $row->first_used_at,
                'last_used_at' => $row->last_used_at,
                'expires_at' => $row->expires_at,
            ]);

        $mobileMoneyRows = (clone $transactionQuery)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(fn ($transaction) => [
                'id' => $transaction->id,
                'msisdn' => $transaction->msisdn,
                'voucher_code' => $transaction->voucher_code,
                'voucher_type' => $transaction->voucher_type,
                'amount' => (float) $transaction->amount,
                'external_ref' => $transaction->external_ref,
                'transaction_ref' => $transaction->transaction_ref,
                'created_at' => $transaction->created_at,
            ]);

        $payload = [
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
            'vouchers' => $voucherRows,
            'mobile_money_rows' => $mobileMoneyRows,
            'cache' => ['source' => 'database', 'ttl_seconds' => 300],
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
    }

    private function resolvePerformancePeriod(string $period): array
    {
        $now = now();

        return match ($period) {
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'hour'],
            'week' => [$now->copy()->startOfWeek(), $now, 'day'],
            'month' => [$now->copy()->startOfMonth(), $now, 'day'],
            'three_months' => [$now->copy()->subMonths(3)->startOfDay(), $now, 'month'],
            'six_months' => [$now->copy()->subMonths(6)->startOfDay(), $now, 'month'],
            default => [$now->copy()->startOfDay(), $now, 'hour'],
        };
    }

    private function attachFeeDetails($transactions): void
    {
        if (!Schema::connection('central')->hasTable('platform_fees')) {
            return;
        }

        $tenant = app('tenant');
        $refs = collect($transactions->items())
            ->pluck('external_ref')
            ->filter()
            ->values();

        if ($refs->isEmpty()) {
            return;
        }

        $fees = PlatformFee::where('tenant_id', $tenant->id)
            ->whereIn('transaction_ref', $refs)
            ->get()
            ->keyBy('transaction_ref');

        foreach ($transactions->items() as $transaction) {
            $fee = $fees->get($transaction->external_ref);
            $platformFee = (float) ($fee?->platform_fee ?? 0);
            $netAmount = $fee?->net_amount !== null
                ? (float) $fee->net_amount
                : max((float) $transaction->amount - $platformFee, 0);

            $transaction->setAttribute('telecom_fee', $platformFee);
            $transaction->setAttribute('platform_fee', $platformFee);
            $transaction->setAttribute('net_amount', $netAmount);
            $transaction->setAttribute('fee_percentage', $fee?->fee_percentage !== null ? (float) $fee->fee_percentage : null);
        }
    }

    private function hideManualPaymentVouchers($query)
    {
        $hasCreatedBy = Schema::connection('tenant')->hasColumn('voucher_groups', 'created_by');
        $hasDescription = Schema::connection('tenant')->hasColumn('voucher_groups', 'description');

        if (!Schema::connection('tenant')->hasTable('voucher_groups') || (!$hasCreatedBy && !$hasDescription)) {
            return $query;
        }

        return $query->whereDoesntHave('group', function ($groupQuery) use ($hasCreatedBy, $hasDescription) {
            if ($hasCreatedBy) {
                $groupQuery->where('created_by', 'manual-payment');
            }

            if ($hasDescription) {
                $groupQuery->orWhere('description', 'like', '%Auto-created by manual payment%');
            }
        });
    }

    private function applyTransactionSiteScope($query, $site): void
    {
        if (!$site) {
            return;
        }

        if ($this->siteUsesDedicatedDatabase($site)) {
            return;
        }

        $hasSiteId = Schema::connection('tenant')->hasColumn('transactions', 'site_id');
        $hasOriginSite = Schema::connection('tenant')->hasColumn('transactions', 'origin_site');

        if ($hasSiteId) {
            $query->where(function ($scope) use ($site, $hasOriginSite) {
                $scope->where('transactions.site_id', $site->id)
                    ->orWhere(function ($legacy) use ($site, $hasOriginSite) {
                        $legacy->whereNull('transactions.site_id');

                        if ($hasOriginSite && !$this->siteUsesDedicatedDatabase($site)) {
                            $legacy->whereIn('transactions.origin_site', $this->siteOriginLabels($site));
                        }
                    });
            });

            return;
        }

        if ($hasOriginSite) {
            $query->whereIn('transactions.origin_site', $this->siteOriginLabels($site));
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function siteUsesDedicatedDatabase($site): bool
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;

        return filled($site?->database_name)
            && (!$tenant || (string) $site->database_name !== (string) $tenant->database_name);
    }

    private function siteOriginLabels($site): array
    {
        $slugLabel = str_replace('-', ' ', (string) $site->slug);

        return collect([
            $site->name,
            $site->slug,
            $slugLabel,
            Str::headline((string) $site->slug),
            Str::upper((string) $site->name),
            Str::upper($slugLabel),
        ])
            ->filter()
            ->map(fn ($value) => trim((string) $value))
            ->unique()
            ->values()
            ->all();
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
