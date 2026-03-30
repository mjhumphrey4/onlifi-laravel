<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\VoucherGroup;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class VoucherService
{
    public function assignVoucherToTransaction(string $externalRef): array
    {
        $transaction = Transaction::where('external_ref', $externalRef)->first();

        if (!$transaction) {
            return ['success' => false, 'error' => 'Transaction not found'];
        }

        if ($transaction->status !== 'success') {
            return ['success' => false, 'error' => 'Transaction not successful'];
        }

        if ($transaction->voucher_code) {
            return [
                'success' => true,
                'voucherCode' => $transaction->voucher_code,
                'message' => 'Voucher already assigned',
            ];
        }

        $voucherType = $transaction->voucher_type;
        $amount = $transaction->amount;

        $voucher = Voucher::where('status', 'unused')
            ->where('price', $amount)
            ->whereHas('group', function($query) use ($voucherType) {
                if ($voucherType) {
                    $query->where('group_name', 'LIKE', "%{$voucherType}%");
                }
            })
            ->first();

        if (!$voucher) {
            $voucher = $this->createVoucherForTransaction($transaction);
            
            if (!$voucher) {
                return ['success' => false, 'error' => 'No vouchers available'];
            }
        }

        $voucher->update([
            'status' => 'used',
            'first_used_at' => now(),
            'last_used_at' => now(),
            'expires_at' => now()->addHours($voucher->validity_hours),
            'used_by_mac' => $transaction->client_mac,
        ]);

        $transaction->update([
            'voucher_code' => $voucher->voucher_code,
        ]);

        Log::info("Voucher assigned to transaction", [
            'external_ref' => $externalRef,
            'voucher_code' => $voucher->voucher_code,
        ]);

        return [
            'success' => true,
            'voucherCode' => $voucher->voucher_code,
            'password' => $voucher->password,
            'expiresAt' => $voucher->expires_at,
        ];
    }

    private function createVoucherForTransaction(Transaction $transaction): ?Voucher
    {
        $group = VoucherGroup::where('price', $transaction->amount)->first();

        if (!$group) {
            $group = VoucherGroup::create([
                'group_name' => 'Auto_' . $transaction->amount . '_UGX',
                'description' => 'Auto-generated voucher group',
                'profile_name' => 'default',
                'validity_hours' => $this->getValidityHoursFromAmount($transaction->amount),
                'price' => $transaction->amount,
                'created_by' => 'system',
            ]);
        }

        return $this->createVoucher($group);
    }

    private function createVoucher(VoucherGroup $group): Voucher
    {
        $voucher = Voucher::create([
            'voucher_code' => $this->generateVoucherCode(),
            'password' => $this->generateVoucherPassword(),
            'group_id' => $group->id,
            'profile_name' => $group->profile_name,
            'validity_hours' => $group->validity_hours,
            'data_limit_mb' => $group->data_limit_mb,
            'speed_limit_kbps' => $group->speed_limit_kbps,
            'price' => $group->price,
            'status' => 'unused',
        ]);

        $radiusService = app(FreeRadiusService::class);
        $radiusService->syncVoucherToRadius([
            'voucher_code' => $voucher->voucher_code,
            'password' => $voucher->password,
            'validity_hours' => $voucher->validity_hours,
            'data_limit_mb' => $voucher->data_limit_mb,
            'speed_limit_kbps' => $voucher->speed_limit_kbps,
        ]);

        return $voucher;
    }

    private function generateVoucherCode(string $format = 'mixed', int $length = 8): string
    {
        do {
            switch ($format) {
                case 'numbers':
                    // All numbers
                    $code = '';
                    for ($i = 0; $i < $length; $i++) {
                        $code .= rand(0, 9);
                    }
                    break;
                case 'letters':
                    // All letters
                    $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, $length));
                    break;
                case 'mixed':
                default:
                    // Mixed alphanumeric (exclude confusing chars: 0, O, I, 1)
                    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                    $code = '';
                    for ($i = 0; $i < $length; $i++) {
                        $code .= $chars[rand(0, strlen($chars) - 1)];
                    }
                    break;
            }
        } while (Voucher::where('voucher_code', $code)->exists());

        return $code;
    }

    private function generateVoucherPassword(string $format = 'mixed', int $length = 8): string
    {
        switch ($format) {
            case 'numbers':
                $password = '';
                for ($i = 0; $i < $length; $i++) {
                    $password .= rand(0, 9);
                }
                return $password;
            case 'letters':
                return strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, $length));
            case 'mixed':
            default:
                return strtoupper(Str::random($length));
        }
    }

    private function getValidityHoursFromAmount(float $amount): int
    {
        $validityMap = [
            200 => 1,
            1000 => 6,
            2000 => 12,
            5000 => 24,
        ];

        return $validityMap[$amount] ?? 1;
    }

    public function generateVoucherBatch(array $data): array
    {
        $group = VoucherGroup::create([
            'group_name' => $data['group_name'],
            'description' => $data['description'] ?? null,
            'profile_name' => $data['profile_name'],
            'validity_hours' => $data['validity_hours'],
            'data_limit_mb' => $data['data_limit_mb'] ?? null,
            'speed_limit_kbps' => $data['speed_limit_kbps'] ?? null,
            'price' => $data['price'],
            'sales_point_id' => $data['sales_point_id'] ?? null,
            'created_by' => $data['created_by'] ?? 'admin',
        ]);

        $count = $data['count'] ?? 10;
        $codeFormat = $data['code_format'] ?? 'mixed'; // numbers, letters, mixed
        $codeLength = $data['code_length'] ?? 8;
        
        // Generate vouchers in bulk for better performance
        $vouchersData = [];
        $now = now();
        
        for ($i = 0; $i < $count; $i++) {
            $vouchersData[] = [
                'voucher_code' => $this->generateVoucherCode($codeFormat, $codeLength),
                'password' => $this->generateVoucherPassword($codeFormat, $codeLength),
                'voucher_group_id' => $group->id,
                'profile_name' => $group->profile_name,
                'validity_hours' => $group->validity_hours,
                'data_limit_mb' => $group->data_limit_mb,
                'speed_limit_kbps' => $group->speed_limit_kbps,
                'price' => $group->price,
                'sales_point_id' => $group->sales_point_id,
                'status' => 'unused',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bulk insert vouchers (much faster than individual inserts)
        Voucher::insert($vouchersData);
        
        // Get the inserted vouchers
        $vouchers = Voucher::where('voucher_group_id', $group->id)
            ->where('created_at', $now)
            ->get();

        // Batch sync to RADIUS (if enabled)
        try {
            $radiusService = app(FreeRadiusService::class);
            foreach ($vouchers as $voucher) {
                $radiusService->syncVoucherToRadius([
                    'voucher_code' => $voucher->voucher_code,
                    'password' => $voucher->password,
                    'validity_hours' => $voucher->validity_hours,
                    'data_limit_mb' => $voucher->data_limit_mb,
                    'speed_limit_kbps' => $voucher->speed_limit_kbps,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('RADIUS sync failed during batch generation', ['error' => $e->getMessage()]);
            // Don't fail the entire batch if RADIUS sync fails
        }

        return [
            'success' => true,
            'group' => $group,
            'vouchers' => $vouchers,
            'count' => $vouchers->count(),
        ];
    }
}
