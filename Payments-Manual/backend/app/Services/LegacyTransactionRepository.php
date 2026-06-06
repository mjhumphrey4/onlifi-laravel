<?php

namespace App\Services;

use App\Models\ManualPaymentSetting;
use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

class LegacyTransactionRepository
{
    private const CONNECTION = 'legacy_manual';

    public function testConnection(): array
    {
        try {
            $connection = $this->connection();
            $table = $this->table();
            $exists = Schema::connection(self::CONNECTION)->hasTable($table);

            return [
                'ok' => $exists,
                'message' => $exists ? 'Connected to legacy manual payment database.' : "Connected, but table '{$table}' was not found.",
                'table' => $table,
                'database' => $connection->getDatabaseName(),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'table' => $this->safeTableName(),
                'database' => ManualPaymentSetting::value('legacy_db_name', ''),
            ];
        }
    }

    public function dashboard(): array
    {
        $status = $this->testConnection();

        if (! $status['ok']) {
            return [
                'database' => $status,
                'summary' => $this->emptySummary(),
                'status_breakdown' => [],
                'daily_revenue' => [],
                'top_sites' => [],
                'recent_transactions' => [],
            ];
        }

        $base = $this->query();
        $today = Carbon::today();
        $last30 = Carbon::today()->subDays(29);

        return [
            'database' => $status,
            'summary' => [
                'total_transactions' => (clone $base)->count(),
                'successful_transactions' => (clone $base)->where('status', 'success')->count(),
                'pending_transactions' => (clone $base)->where('status', 'pending')->count(),
                'failed_transactions' => (clone $base)->where('status', 'failed')->count(),
                'gross_revenue' => (float) (clone $base)->where('status', 'success')->sum('amount'),
                'today_revenue' => (float) (clone $base)->where('status', 'success')->whereDate('created_at', $today)->sum('amount'),
                'today_transactions' => (clone $base)->whereDate('created_at', $today)->count(),
                'average_success_value' => (float) (clone $base)->where('status', 'success')->avg('amount'),
            ],
            'status_breakdown' => $this->statusBreakdown($base),
            'daily_revenue' => $this->dailyRevenue($base, $last30, Carbon::today()),
            'top_sites' => $this->topSites($base),
            'recent_transactions' => $this->list(['limit' => 8])['data'],
        ];
    }

    public function list(array $filters = []): array
    {
        $status = $this->testConnection();

        if (! $status['ok']) {
            return [
                'data' => [],
                'meta' => [
                    'page' => 1,
                    'per_page' => 25,
                    'total' => 0,
                    'database' => $status,
                ],
            ];
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['per_page'] ?? $filters['limit'] ?? 25), 1), 100);
        $query = $this->filteredQuery($filters);
        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(fn ($row) => $this->normalizeRow((array) $row))
            ->values()
            ->all();

        return [
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'database' => $status,
            ],
        ];
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = $this->query();

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function (Builder $nested) use ($search) {
                foreach (['external_ref', 'transaction_ref', 'msisdn', 'voucher_code', 'origin_site', 'client_mac', 'status'] as $column) {
                    $nested->orWhere($column, 'like', $search);
                }
            });
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        return $query;
    }

    private function statusBreakdown(Builder $base): array
    {
        return (clone $base)
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('status')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status ?: 'unknown',
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ])
            ->all();
    }

    private function dailyRevenue(Builder $base, CarbonInterface $start, CarbonInterface $end): array
    {
        $format = '%Y-%m-%d';
        $rows = (clone $base)
            ->selectRaw("DATE_FORMAT(created_at, '{$format}') as bucket, COUNT(*) as count, COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as total")
            ->whereDate('created_at', '>=', $start)
            ->whereDate('created_at', '<=', $end)
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = [];
        $cursor = $start->copy();

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m-d');
            $row = $rows->get($key);
            $series[] = [
                'date' => $key,
                'label' => $cursor->format('M d'),
                'count' => (int) ($row?->count ?? 0),
                'total' => (float) ($row?->total ?? 0),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    private function topSites(Builder $base): array
    {
        return (clone $base)
            ->selectRaw("COALESCE(origin_site, 'Unknown') as site, COUNT(*) as count, COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as total")
            ->groupBy('site')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'site' => $row->site,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ])
            ->all();
    }

    private function query(): Builder
    {
        return $this->connection()->table($this->table());
    }

    private function connection(): ConnectionInterface
    {
        Config::set('database.connections.' . self::CONNECTION, [
            'driver' => 'mysql',
            'host' => ManualPaymentSetting::value('legacy_db_host', '127.0.0.1'),
            'port' => ManualPaymentSetting::value('legacy_db_port', 3306),
            'database' => ManualPaymentSetting::value('legacy_db_name', ''),
            'username' => ManualPaymentSetting::value('legacy_db_user', ''),
            'password' => ManualPaymentSetting::value('legacy_db_password', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ]);

        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    private function table(): string
    {
        $table = $this->safeTableName();

        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Invalid legacy transaction table name.');
        }

        return $table;
    }

    private function safeTableName(): string
    {
        return (string) ManualPaymentSetting::value('legacy_transactions_table', 'transactions');
    }

    private function normalizeRow(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'external_ref' => $row['external_ref'] ?? null,
            'transaction_ref' => $row['transaction_ref'] ?? null,
            'msisdn' => $row['msisdn'] ?? null,
            'amount' => (float) ($row['amount'] ?? 0),
            'status' => $row['status'] ?? 'unknown',
            'status_message' => $row['status_message'] ?? null,
            'origin_site' => $row['origin_site'] ?? null,
            'client_mac' => $row['client_mac'] ?? null,
            'email' => $row['email'] ?? null,
            'voucher_type' => $row['voucher_type'] ?? null,
            'voucher_code' => $row['voucher_code'] ?? null,
            'network_ref' => $row['network_ref'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'total_transactions' => 0,
            'successful_transactions' => 0,
            'pending_transactions' => 0,
            'failed_transactions' => 0,
            'gross_revenue' => 0,
            'today_revenue' => 0,
            'today_transactions' => 0,
            'average_success_value' => 0,
        ];
    }
}
