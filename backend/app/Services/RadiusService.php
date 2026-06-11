<?php

namespace App\Services;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RadiusService - Syncs vouchers with FreeRADIUS tables
 * 
 * This service ensures that when vouchers are created, updated, or disabled,
 * the corresponding entries in radcheck and radreply tables are kept in sync.
 */
class RadiusService
{
    /**
     * Sync a voucher to RADIUS tables (radcheck and radreply)
     */
    public function syncVoucher(Voucher $voucher): bool
    {
        if (in_array($voucher->status, ['used', 'expired', 'disabled'], true)) {
            return $this->disableVoucher($voucher);
        }

        try {
            DB::connection('tenant')->beginTransaction();
            
            // Insert/update radcheck (password)
            DB::connection('tenant')->table('radcheck')->updateOrInsert(
                ['username' => $voucher->voucher_code, 'attribute' => 'Cleartext-Password'],
                ['op' => ':=', 'value' => $voucher->password ?: $voucher->voucher_code]
            );
            
            // Clear existing reply attributes for this voucher
            DB::connection('tenant')->table('radreply')
                ->where('username', $voucher->voucher_code)
                ->delete();
            
            // Calculate remaining session time. Once first_used_at is known, the
            // voucher is an absolute wall-clock lease from that first login.
            $remainingSeconds = $this->remainingSessionSeconds($voucher);
            
            // Session-Timeout attribute
            if ($remainingSeconds > 0) {
                DB::connection('tenant')->table('radreply')->insert([
                    'username' => $voucher->voucher_code,
                    'attribute' => 'Session-Timeout',
                    'op' => '=',
                    'value' => (string) $remainingSeconds,
                ]);
            }
            
            // Mikrotik-Rate-Limit attribute (speed limit)
            if ($voucher->speed_limit_kbps) {
                $rateLimit = "{$voucher->speed_limit_kbps}k/{$voucher->speed_limit_kbps}k";
                DB::connection('tenant')->table('radreply')->insert([
                    'username' => $voucher->voucher_code,
                    'attribute' => 'Mikrotik-Rate-Limit',
                    'op' => '=',
                    'value' => $rateLimit,
                ]);
            }
            
            // Mikrotik-Total-Limit attribute (data limit in bytes)
            if ($voucher->data_limit_mb) {
                $remainingDataMb = $voucher->data_limit_mb - ($voucher->total_data_used_mb ?? 0);
                $remainingDataBytes = max(0, (int) round($remainingDataMb * 1048576));
                
                if ($remainingDataBytes > 0) {
                    foreach ($this->mikrotikTotalLimitAttributes($remainingDataBytes) as $attribute => $value) {
                        DB::connection('tenant')->table('radreply')->insert([
                            'username' => $voucher->voucher_code,
                            'attribute' => $attribute,
                            'op' => '=',
                            'value' => (string) $value,
                        ]);
                    }
                }
            }

            DB::connection('tenant')->table('radreply')->insert([
                'username' => $voucher->voucher_code,
                'attribute' => 'Acct-Interim-Interval',
                'op' => '=',
                'value' => '300',
            ]);
            
            DB::connection('tenant')->commit();
            
            Log::info('Voucher synced to RADIUS', [
                'voucher_code' => $voucher->voucher_code,
                'session_timeout' => $remainingSeconds,
            ]);
            
            return true;
        } catch (\Exception $e) {
            DB::connection('tenant')->rollBack();
            Log::error('Failed to sync voucher to RADIUS', [
                'voucher_code' => $voucher->voucher_code,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Sync multiple vouchers (batch operation)
     */
    public function syncVouchers(array $vouchers): int
    {
        $synced = 0;
        foreach ($vouchers as $voucher) {
            if ($this->syncVoucher($voucher)) {
                $synced++;
            }
        }
        return $synced;
    }
    
    /**
     * Disable a voucher in RADIUS (remove from radcheck/radreply)
     */
    public function disableVoucher(Voucher $voucher): bool
    {
        try {
            DB::connection('tenant')->table('radcheck')
                ->where('username', $voucher->voucher_code)
                ->delete();
                
            DB::connection('tenant')->table('radreply')
                ->where('username', $voucher->voucher_code)
                ->delete();
            
            Log::info('Voucher disabled in RADIUS', ['voucher_code' => $voucher->voucher_code]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to disable voucher in RADIUS', [
                'voucher_code' => $voucher->voucher_code,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Check if a voucher exists in RADIUS
     */
    public function voucherExistsInRadius(string $voucherCode): bool
    {
        return DB::connection('tenant')->table('radcheck')
            ->where('username', $voucherCode)
            ->exists();
    }
    
    /**
     * Get active sessions for a voucher from radacct
     */
    public function getActiveSessions(string $voucherCode): array
    {
        return DB::connection('tenant')->table('radacct')
            ->where('username', $voucherCode)
            ->whereNull('acctstoptime')
            ->get()
            ->toArray();
    }
    
    /**
     * Get session history for a voucher
     */
    public function getSessionHistory(string $voucherCode, int $limit = 50): array
    {
        return DB::connection('tenant')->table('radacct')
            ->where('username', $voucherCode)
            ->orderBy('acctstarttime', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
    
    /**
     * Disconnect a user session (requires CoA support on MikroTik)
     * This inserts a record that can be processed by a CoA script
     */
    public function disconnectSession(string $sessionId): bool
    {
        // Note: Actual CoA (Change of Authorization) requires additional setup
        // This is a placeholder for the disconnect functionality
        Log::info('Disconnect requested for session', ['session_id' => $sessionId]);
        return true;
    }
    
    /**
     * Sync all unused/active vouchers to RADIUS
     * Useful for initial setup or recovery
     */
    public function syncAllActiveVouchers(): array
    {
        $vouchers = Voucher::whereIn('status', ['unused', 'reserved', 'in_use'])
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get();
        
        $synced = 0;
        $failed = 0;
        
        foreach ($vouchers as $voucher) {
            if ($this->syncVoucher($voucher)) {
                $synced++;
            } else {
                $failed++;
            }
        }
        
        return [
            'total' => $vouchers->count(),
            'synced' => $synced,
            'failed' => $failed,
        ];
    }
    
    /**
     * Clean up expired vouchers from RADIUS
     */
    public function cleanupExpiredVouchers(): int
    {
        $expiredVouchers = Voucher::whereIn('status', ['used', 'expired'])
            ->orWhere('status', 'disabled')
            ->orWhere(function ($query) {
                $query->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now());
            })
            ->get();
        
        $cleaned = 0;
        foreach ($expiredVouchers as $voucher) {
            if ($this->disableVoucher($voucher)) {
                $cleaned++;
            }
        }
        
        return $cleaned;
    }

    public function remainingSessionSeconds(Voucher $voucher): int
    {
        $validitySeconds = $this->validitySeconds($voucher);

        if ($voucher->expires_at) {
            return max(0, now()->diffInSeconds($voucher->expires_at, false));
        }

        if ($voucher->first_used_at) {
            return max(0, now()->diffInSeconds($voucher->first_used_at->copy()->addSeconds($validitySeconds), false));
        }

        return $validitySeconds;
    }

    public function validitySeconds(Voucher $voucher): int
    {
        $hours = max(0, (int) $voucher->validity_hours);
        $minutes = max(0, (int) ($voucher->validity_minutes ?? 0));

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

    private function mikrotikTotalLimitAttributes(int $bytes): array
    {
        $gigaword = 4294967296;

        return [
            'Mikrotik-Total-Limit' => $bytes % $gigaword,
            'Mikrotik-Total-Limit-Gigawords' => intdiv($bytes, $gigaword),
        ];
    }
}
