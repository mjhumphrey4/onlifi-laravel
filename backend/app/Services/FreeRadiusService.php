<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FreeRadiusService
{
    public function syncVoucherToRadius(array $voucherData): bool
    {
        try {
            DB::connection('tenant')->beginTransaction();

            $username = $voucherData['voucher_code'];
            $password = $voucherData['password'] ?: $voucherData['voucher_code'];
            $validityHours = $voucherData['validity_hours'];
            $validityMinutes = $voucherData['validity_minutes'] ?? null;
            $dataLimitMb = $voucherData['data_limit_mb'];
            $speedLimitKbps = $voucherData['speed_limit_kbps'];

            $this->insertRadcheck($username, $password);

            $this->insertRadreply($username, $validityHours, $dataLimitMb, $speedLimitKbps, $validityMinutes);

            DB::connection('tenant')->commit();

            Log::info('Voucher synced to FreeRADIUS', [
                'username' => $username,
                'validity_hours' => $validityHours,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::connection('tenant')->rollBack();
            
            Log::error('Failed to sync voucher to FreeRADIUS', [
                'error' => $e->getMessage(),
                'voucher' => $voucherData['voucher_code'] ?? 'unknown',
            ]);

            return false;
        }
    }

    private function insertRadcheck(string $username, string $password): void
    {
        // Only insert Cleartext-Password
        // FreeRADIUS will automatically detect auth type (PAP/CHAP/MSCHAP)
        // Do NOT set Auth-Type as it interferes with auto-detection
        DB::connection('tenant')->table('radcheck')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Cleartext-Password'],
            [
                'op' => ':=',
                'value' => $password,
            ]
        );
    }

    private function insertRadreply(string $username, int $validityHours, ?int $dataLimitMb, ?int $speedLimitKbps, ?int $validityMinutes = null): void
    {
        $sessionTimeout = $this->validitySeconds($validityHours, $validityMinutes);
        DB::connection('tenant')->table('radreply')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Session-Timeout'],
            [
                'op' => '=',
                'value' => (string)$sessionTimeout,
            ]
        );

        $idleTimeout = 900;
        DB::connection('tenant')->table('radreply')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Idle-Timeout'],
            [
                'op' => '=',
                'value' => (string)$idleTimeout,
            ]
        );

        if ($dataLimitMb) {
            $dataLimitBytes = $dataLimitMb * 1048576;
            foreach ($this->mikrotikTotalLimitAttributes((int) $dataLimitBytes) as $attribute => $value) {
                DB::connection('tenant')->table('radreply')->updateOrInsert(
                    ['username' => $username, 'attribute' => $attribute],
                    [
                        'op' => '=',
                        'value' => (string) $value,
                    ]
                );
            }
        }

        if ($speedLimitKbps) {
            $speedLimit = "{$speedLimitKbps}k/{$speedLimitKbps}k";
            DB::connection('tenant')->table('radreply')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Mikrotik-Rate-Limit'],
                [
                    'op' => '=',
                    'value' => $speedLimit,
                ]
            );
        }

        DB::connection('tenant')->table('radreply')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Acct-Interim-Interval'],
            [
                'op' => '=',
                'value' => '300',
            ]
        );
    }

    public function removeVoucherFromRadius(string $username): bool
    {
        try {
            DB::connection('tenant')->table('radcheck')
                ->where('username', $username)
                ->delete();

            DB::connection('tenant')->table('radreply')
                ->where('username', $username)
                ->delete();

            Log::info('Voucher removed from FreeRADIUS', ['username' => $username]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to remove voucher from FreeRADIUS', [
                'error' => $e->getMessage(),
                'username' => $username,
            ]);

            return false;
        }
    }

    public function syncBatchToRadius(iterable $vouchers): array
    {
        $items = collect($vouchers)->values();

        if ($items->isEmpty()) {
            return ['synced' => 0, 'failed' => 0, 'total' => 0];
        }

        try {
            DB::connection('tenant')->beginTransaction();

            $usernames = $items->pluck('voucher_code')->filter()->values()->all();

            DB::connection('tenant')->table('radcheck')->whereIn('username', $usernames)->delete();
            DB::connection('tenant')->table('radreply')->whereIn('username', $usernames)->delete();

            $radcheckRows = [];
            $radreplyRows = [];

            foreach ($items as $voucher) {
                $radcheckRows[] = [
                    'username' => $voucher->voucher_code,
                    'attribute' => 'Cleartext-Password',
                    'op' => ':=',
                    'value' => $voucher->password ?: $voucher->voucher_code,
                ];

                $radreplyRows[] = [
                    'username' => $voucher->voucher_code,
                    'attribute' => 'Session-Timeout',
                    'op' => '=',
                    'value' => (string) $this->remainingSessionSeconds($voucher),
                ];

                $radreplyRows[] = [
                    'username' => $voucher->voucher_code,
                    'attribute' => 'Idle-Timeout',
                    'op' => '=',
                    'value' => '900',
                ];

                if ($voucher->data_limit_mb) {
                    $remainingBytes = $this->remainingDataBytes($voucher);
                    if ($remainingBytes > 0) {
                        foreach ($this->mikrotikTotalLimitAttributes($remainingBytes) as $attribute => $value) {
                            $radreplyRows[] = [
                                'username' => $voucher->voucher_code,
                                'attribute' => $attribute,
                                'op' => '=',
                                'value' => (string) $value,
                            ];
                        }
                    }
                }

                if ($voucher->speed_limit_kbps) {
                    $radreplyRows[] = [
                        'username' => $voucher->voucher_code,
                        'attribute' => 'Mikrotik-Rate-Limit',
                        'op' => '=',
                        'value' => "{$voucher->speed_limit_kbps}k/{$voucher->speed_limit_kbps}k",
                    ];
                }

                $radreplyRows[] = [
                    'username' => $voucher->voucher_code,
                    'attribute' => 'Acct-Interim-Interval',
                    'op' => '=',
                    'value' => '300',
                ];
            }

            foreach (array_chunk($radcheckRows, 500) as $chunk) {
                DB::connection('tenant')->table('radcheck')->insert($chunk);
            }

            foreach (array_chunk($radreplyRows, 500) as $chunk) {
                DB::connection('tenant')->table('radreply')->insert($chunk);
            }

            DB::connection('tenant')->commit();

            Log::info('Voucher batch synced to FreeRADIUS', ['count' => $items->count()]);

            return [
                'synced' => $items->count(),
                'failed' => 0,
                'total' => $items->count(),
            ];
        } catch (\Exception $e) {
            DB::connection('tenant')->rollBack();

            Log::error('Failed to batch sync vouchers to FreeRADIUS', [
                'error' => $e->getMessage(),
                'count' => $items->count(),
            ]);

            return [
                'synced' => 0,
                'failed' => $items->count(),
                'total' => $items->count(),
            ];
        }
    }

    public function checkRadiusAuth(string $username, string $password): bool
    {
        try {
            $radcheck = DB::connection('tenant')->table('radcheck')
                ->where('username', $username)
                ->where('attribute', 'Cleartext-Password')
                ->where('value', $password)
                ->first();

            return $radcheck !== null;
        } catch (\Exception $e) {
            Log::error('RADIUS auth check failed', [
                'error' => $e->getMessage(),
                'username' => $username,
            ]);

            return false;
        }
    }

    public function getRadiusSessionLimits(string $username): ?array
    {
        try {
            $limits = DB::connection('tenant')->table('radreply')
                ->where('username', $username)
                ->get();

            if ($limits->isEmpty()) {
                return null;
            }

            $result = [];
            foreach ($limits as $limit) {
                $result[$limit->attribute] = $limit->value;
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to get RADIUS session limits', [
                'error' => $e->getMessage(),
                'username' => $username,
            ]);

            return null;
        }
    }

    private function validitySeconds(int $validityHours, ?int $validityMinutes = null): int
    {
        $hours = max(0, $validityHours);
        $minutes = max(0, (int) ($validityMinutes ?? 0));

        if ($minutes > 0) {
            $secondsFromMinutes = $minutes * 60;
            $secondsFromHours = $hours * 3600;

            if ($secondsFromHours > 0 && $secondsFromMinutes < $secondsFromHours) {
                return max(60, $secondsFromHours);
            }

            return max(60, $secondsFromMinutes);
        }

        return max(60, $hours * 3600);
    }

    private function remainingSessionSeconds($voucher): int
    {
        $validitySeconds = $this->validitySeconds((int) $voucher->validity_hours, $voucher->validity_minutes ? (int) $voucher->validity_minutes : null);

        if (!empty($voucher->expires_at)) {
            return max(0, now()->diffInSeconds($voucher->expires_at, false));
        }

        if (!empty($voucher->first_used_at)) {
            return max(0, now()->diffInSeconds($voucher->first_used_at->copy()->addSeconds($validitySeconds), false));
        }

        return $validitySeconds;
    }

    private function remainingDataBytes($voucher): int
    {
        $limitMb = (float) ($voucher->data_limit_mb ?? 0);
        $usedMb = (float) ($voucher->total_data_used_mb ?? 0);

        return max(0, (int) round(($limitMb - $usedMb) * 1048576));
    }

    private function mikrotikTotalLimitAttributes(int $bytes): array
    {
        $gigaword = 4294967296;

        return [
            'Mikrotik-Total-Limit' => $bytes % $gigaword,
            'Mikrotik-Total-Limit-Gigawords' => intdiv($bytes, $gigaword),
        ];
    }
}
