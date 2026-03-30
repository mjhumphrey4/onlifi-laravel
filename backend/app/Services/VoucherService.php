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

    private function generateVoucherCode(string $format = 'mixed', int $length = 8, array $existingCodes = []): string
    {
        $maxAttempts = 100;
        $attempts = 0;
        
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
            $attempts++;
        } while ((in_array($code, $existingCodes) || Voucher::where('voucher_code', $code)->exists()) && $attempts < $maxAttempts);

        if ($attempts >= $maxAttempts) {
            throw new \Exception('Failed to generate unique voucher code after ' . $maxAttempts . ' attempts');
        }

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
        
        // Generate all voucher codes first (in memory) to avoid repeated DB checks
        $generatedCodes = [];
        $vouchersData = [];
        $now = now();
        
        Log::info("Starting voucher generation", ['count' => $count, 'format' => $codeFormat]);
        
        for ($i = 0; $i < $count; $i++) {
            $code = $this->generateVoucherCode($codeFormat, $codeLength, $generatedCodes);
            $generatedCodes[] = $code;
            
            $vouchersData[] = [
                'voucher_code' => $code,
                'password' => $this->generateVoucherPassword($codeFormat, $codeLength),
                'group_id' => $group->id,
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

        Log::info("Generated all voucher codes", ['count' => count($vouchersData)]);

        // Insert vouchers in chunks for better performance with large batches
        $chunkSize = 100;
        $chunks = array_chunk($vouchersData, $chunkSize);
        
        foreach ($chunks as $index => $chunk) {
            Voucher::insert($chunk);
            Log::info("Inserted voucher chunk", ['chunk' => $index + 1, 'size' => count($chunk)]);
        }
        
        // Get the inserted vouchers
        $vouchers = Voucher::where('group_id', $group->id)
            ->where('created_at', $now)
            ->get();

        Log::info("Retrieved inserted vouchers", ['count' => $vouchers->count()]);

        // Batch sync to RADIUS (if enabled) - do this asynchronously to avoid timeout
        try {
            $radiusService = app(FreeRadiusService::class);
            $syncCount = 0;
            
            foreach ($vouchers as $voucher) {
                try {
                    $radiusService->syncVoucherToRadius([
                        'voucher_code' => $voucher->voucher_code,
                        'password' => $voucher->password,
                        'validity_hours' => $voucher->validity_hours,
                        'data_limit_mb' => $voucher->data_limit_mb,
                        'speed_limit_kbps' => $voucher->speed_limit_kbps,
                    ]);
                    $syncCount++;
                } catch (\Exception $e) {
                    Log::warning('RADIUS sync failed for voucher', [
                        'voucher_code' => $voucher->voucher_code,
                        'error' => $e->getMessage()
                    ]);
                    // Continue with next voucher
                }
            }
            
            Log::info("RADIUS sync completed", ['synced' => $syncCount, 'total' => $vouchers->count()]);
        } catch (\Exception $e) {
            Log::warning('RADIUS sync failed during batch generation', ['error' => $e->getMessage()]);
            // Don't fail the entire batch if RADIUS sync fails
        }

        return [
            'success' => true,
            'group' => $group,
            'vouchers' => $vouchers,
            'count' => $vouchers->count(),
            'message' => "Successfully generated {$vouchers->count()} vouchers",
        ];
    }
}
