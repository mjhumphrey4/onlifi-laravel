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
            $password = $voucherData['password'];
            $validityHours = $voucherData['validity_hours'];
            $dataLimitMb = $voucherData['data_limit_mb'];
            $speedLimitKbps = $voucherData['speed_limit_kbps'];

            $this->insertRadcheck($username, $password);

            $this->insertRadreply($username, $validityHours, $dataLimitMb, $speedLimitKbps);

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
        DB::connection('tenant')->table('radcheck')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Cleartext-Password'],
            [
                'op' => ':=',
                'value' => $password,
            ]
        );

        DB::connection('tenant')->table('radcheck')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Auth-Type'],
            [
                'op' => ':=',
                'value' => 'Accept',
            ]
        );
    }

    private function insertRadreply(string $username, int $validityHours, ?int $dataLimitMb, ?int $speedLimitKbps): void
    {
        $sessionTimeout = $validityHours * 3600;
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
            DB::connection('tenant')->table('radreply')->updateOrInsert(
                ['username' => $username, 'attribute' => 'Mikrotik-Total-Limit'],
                [
                    'op' => '=',
                    'value' => (string)$dataLimitBytes,
                ]
            );
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

    public function syncBatchToRadius(array $vouchers): array
    {
        $synced = 0;
        $failed = 0;

        foreach ($vouchers as $voucher) {
            $voucherData = [
                'voucher_code' => $voucher->voucher_code,
                'password' => $voucher->password,
                'validity_hours' => $voucher->validity_hours,
                'data_limit_mb' => $voucher->data_limit_mb,
                'speed_limit_kbps' => $voucher->speed_limit_kbps,
            ];

            if ($this->syncVoucherToRadius($voucherData)) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'total' => count($vouchers),
        ];
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
}
