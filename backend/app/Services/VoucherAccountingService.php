<?php

namespace App\Services;

use App\Models\MikrotikRouter;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\Voucher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class VoucherAccountingService
{
    public function __construct(
        private RadiusService $radiusService,
        private MikrotikService $mikrotikService
    ) {
    }

    public function cleanupAllTenants(bool $kick = true): array
    {
        $summary = [
            'tenants' => 0,
            'sites' => 0,
            'reconciled' => 0,
            'expired' => 0,
            'kicked' => 0,
            'failed' => 0,
        ];

        Tenant::whereNotNull('database_name')->orderBy('id')->chunkById(25, function ($tenants) use (&$summary, $kick) {
            foreach ($tenants as $tenant) {
                $summary['tenants']++;

                try {
                    $sites = Site::where('tenant_id', $tenant->id)->get();

                    if ($sites->isEmpty()) {
                        $tenant->configure();
                        $result = $this->cleanupCurrentTenantDatabase($kick);
                        $this->mergeSummary($summary, $result);
                        continue;
                    }

                    foreach ($sites as $site) {
                        $summary['sites']++;
                        $site->configureTenantConnection($tenant);
                        $result = $this->cleanupCurrentTenantDatabase($kick, $site);
                        $this->mergeSummary($summary, $result);
                    }
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    Log::error('Voucher accounting cleanup failed for tenant', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $summary;
    }

    public function cleanupCurrentTenantDatabase(bool $kick = true, ?Site $site = null): array
    {
        $summary = [
            'reconciled' => 0,
            'expired' => 0,
            'kicked' => 0,
            'failed' => 0,
        ];

        if (!$this->hasRequiredTables()) {
            return $summary;
        }

        Voucher::whereIn('status', ['unused', 'reserved', 'in_use'])
            ->orderBy('id')
            ->chunkById(250, function ($vouchers) use (&$summary, $kick, $site) {
                foreach ($vouchers as $voucher) {
                    try {
                        $result = $this->reconcileVoucher($voucher, $kick, $site);
                        $this->mergeSummary($summary, $result);
                    } catch (\Throwable $e) {
                        $summary['failed']++;
                        Log::error('Voucher reconciliation failed', [
                            'voucher_code' => $voucher->voucher_code,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return $summary;
    }

    public function reconcileVoucher(Voucher $voucher, bool $kick = true, ?Site $site = null): array
    {
        $summary = [
            'reconciled' => 0,
            'expired' => 0,
            'kicked' => 0,
            'failed' => 0,
        ];

        if ($voucher->expires_at && now()->greaterThanOrEqualTo($voucher->expires_at)) {
            $correctedExpiresAt = $voucher->first_used_at
                ? $voucher->first_used_at->copy()->addSeconds($this->radiusService->validitySeconds($voucher))
                : null;

            if ($correctedExpiresAt && $correctedExpiresAt->greaterThan($voucher->expires_at) && $correctedExpiresAt->isFuture()) {
                $voucher->fill($this->filterVoucherColumns([
                    'status' => 'in_use',
                    'expires_at' => $correctedExpiresAt,
                    'expired_reason' => null,
                    'last_accounting_at' => now(),
                ]));
                $voucher->save();
                if ($freshVoucher = $voucher->fresh()) {
                    $this->radiusService->syncVoucher($freshVoucher);
                }
                $summary['reconciled']++;

                return $summary;
            }

            $voucher->fill($this->filterVoucherColumns([
                'status' => 'used',
                'last_accounting_at' => now(),
                'expired_reason' => $voucher->expired_reason ?: 'time_limit',
            ]));
            $voucher->save();

            $this->radiusService->disableVoucher($voucher);
            $this->closeActiveAccountingRows($voucher, 'Session-Timeout');
            $summary['reconciled']++;
            $summary['expired']++;

            if ($kick) {
                $summary['kicked'] += $this->kickVoucherSessions($voucher->voucher_code, $site);
            }

            return $summary;
        }

        $sessions = DB::connection('tenant')->table('radacct')
            ->where('username', $voucher->voucher_code)
            ->orderBy('acctstarttime')
            ->get();

        if ($sessions->isEmpty()) {
            return $summary;
        }

        $firstStart = $sessions
            ->pluck('acctstarttime')
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sort()
            ->first();

        if (!$firstStart) {
            return $summary;
        }

        $sessionSeconds = 0;
        $dataBytes = 0;
        $lastAccountingAt = null;
        $lastMac = null;

        foreach ($sessions as $session) {
            $sessionSeconds += $this->sessionSeconds($session);
            $dataBytes += (int) ($session->acctinputoctets ?? 0) + (int) ($session->acctoutputoctets ?? 0);
            $lastMac = $session->callingstationid ?: $lastMac;

            foreach (['acctupdatetime', 'acctstoptime', 'acctstarttime'] as $field) {
                if (!empty($session->{$field})) {
                    $candidate = Carbon::parse($session->{$field});
                    if (!$lastAccountingAt || $candidate->greaterThan($lastAccountingAt)) {
                        $lastAccountingAt = $candidate;
                    }
                }
            }
        }

        $expiresAt = $voucher->expires_at ?: $firstStart->copy()->addSeconds($this->radiusService->validitySeconds($voucher));
        $dataUsedMb = round($dataBytes / 1048576, 2);
        $expiredByTime = now()->greaterThanOrEqualTo($expiresAt);
        $expiredByData = $voucher->data_limit_mb && $dataUsedMb >= (float) $voucher->data_limit_mb;

        $updates = [
            'status' => $expiredByTime || $expiredByData ? 'used' : 'in_use',
            'first_used_at' => $voucher->first_used_at ?: $firstStart,
            'last_used_at' => $lastAccountingAt ?: now(),
            'expires_at' => $expiresAt,
            'total_data_used_mb' => $dataUsedMb,
            'total_session_time_minutes' => (int) ceil($sessionSeconds / 60),
            'last_accounting_at' => $lastAccountingAt ?: now(),
            'used_by_mac' => $voucher->used_by_mac ?: $lastMac,
            'expired_reason' => $expiredByData ? 'data_limit' : ($expiredByTime ? 'time_limit' : null),
        ];

        $voucher->fill($this->filterVoucherColumns($updates));
        $voucher->save();
        $summary['reconciled']++;

        if ($expiredByTime || $expiredByData) {
            $this->radiusService->disableVoucher($voucher);
            $this->closeActiveAccountingRows($voucher, $expiredByData ? 'Data-Limit' : 'Session-Timeout');
            $summary['expired']++;

            if ($kick) {
                $summary['kicked'] += $this->kickVoucherSessions($voucher->voucher_code, $site);
            }
        } else {
            $this->radiusService->syncVoucher($voucher->fresh());
        }

        return $summary;
    }

    private function sessionSeconds($session): int
    {
        if (!empty($session->acctsessiontime)) {
            return (int) $session->acctsessiontime;
        }

        if (empty($session->acctstarttime)) {
            return 0;
        }

        $start = Carbon::parse($session->acctstarttime);
        $end = !empty($session->acctstoptime) ? Carbon::parse($session->acctstoptime) : now();

        return max(0, $start->diffInSeconds($end));
    }

    private function closeActiveAccountingRows(Voucher $voucher, string $cause): void
    {
        DB::connection('tenant')->table('radacct')
            ->where('username', $voucher->voucher_code)
            ->whereNull('acctstoptime')
            ->update([
                'acctstoptime' => now(),
                'acctupdatetime' => now(),
                'acctterminatecause' => $cause,
            ]);
    }

    private function kickVoucherSessions(string $voucherCode, ?Site $site = null): int
    {
        if (!Schema::connection('tenant')->hasTable('mikrotik_routers')) {
            return 0;
        }

        $query = MikrotikRouter::where('is_active', true);

        if (Schema::connection('tenant')->hasColumn('mikrotik_routers', 'installer_submission_id')) {
            $query->whereNull('installer_submission_id');
        }

        if ($site && Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
            $query->where('site_id', $site->id);
        }

        $kicked = 0;
        foreach ($query->get() as $router) {
            if ($this->mikrotikService->removeActiveHotspotUser($router, $voucherCode)) {
                $kicked++;
            }
        }

        return $kicked;
    }

    private function filterVoucherColumns(array $updates): array
    {
        return collect($updates)
            ->filter(fn ($value, $column) => Schema::connection('tenant')->hasColumn('vouchers', $column))
            ->all();
    }

    private function hasRequiredTables(): bool
    {
        return Schema::connection('tenant')->hasTable('vouchers')
            && Schema::connection('tenant')->hasTable('radacct')
            && Schema::connection('tenant')->hasTable('radcheck')
            && Schema::connection('tenant')->hasTable('radreply');
    }

    private function mergeSummary(array &$summary, array $result): void
    {
        foreach ($result as $key => $value) {
            if (array_key_exists($key, $summary)) {
                $summary[$key] += $value;
            }
        }
    }
}
