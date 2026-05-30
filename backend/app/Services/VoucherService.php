<?php

namespace App\Services;

use App\Models\Voucher;
use App\Models\VoucherGroup;
use App\Models\Transaction;
use App\Support\SiteScope;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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
            ->when(Schema::connection('tenant')->hasColumn('vouchers', 'site_id') && $transaction->site_id, fn ($query) => $query->where('site_id', $transaction->site_id))
            ->first();

        if (!$voucher) {
            $voucher = $this->createVoucherForTransaction($transaction);
            
            if (!$voucher) {
                return ['success' => false, 'error' => 'No vouchers available'];
            }
        }

        $voucher->update([
            'status' => 'used',
            'last_used_at' => null,
            'expires_at' => null,
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
        $group = VoucherGroup::where('price', $transaction->amount)
            ->when(Schema::connection('tenant')->hasColumn('voucher_groups', 'site_id') && $transaction->site_id, fn ($query) => $query->where('site_id', $transaction->site_id))
            ->first();

        if (!$group) {
            $groupData = [
                'group_name' => 'Auto_' . $transaction->amount . '_UGX',
                'description' => 'Auto-generated voucher group',
                'profile_name' => 'default',
                'validity_hours' => $this->getValidityHoursFromAmount($transaction->amount),
                'price' => $transaction->amount,
                'created_by' => 'system',
            ];

            if (Schema::connection('tenant')->hasColumn('voucher_groups', 'site_id') && $transaction->site_id) {
                $groupData['site_id'] = $transaction->site_id;
            }
            $groupData = SiteScope::tenantCompatColumns('voucher_groups', $groupData);

            $group = VoucherGroup::create($groupData);
        }

        return $this->createVoucher($group);
    }

    private function createVoucher(VoucherGroup $group): Voucher
    {
        $code = $this->generateVoucherCode();
        
        $voucherData = [
            'voucher_code' => $code,
            'password' => $code,  // Same as voucher_code for single-entry authentication
            'group_id' => $group->id,
            'profile_name' => $group->profile_name,
            'validity_hours' => $group->validity_hours,
            'validity_minutes' => $group->validity_minutes ?? null,
            'data_limit_mb' => $group->data_limit_mb,
            'speed_limit_kbps' => $group->speed_limit_kbps,
            'price' => $group->price,
            'site_id' => $group->site_id ?? null,
            'status' => 'unused',
        ];

        $voucherData = SiteScope::tenantCompatColumns('vouchers', $voucherData);
        if (!Schema::connection('tenant')->hasColumn('vouchers', 'site_id')) {
            unset($voucherData['site_id']);
        }
        if (!Schema::connection('tenant')->hasColumn('vouchers', 'validity_minutes')) {
            unset($voucherData['validity_minutes']);
        }

        $voucher = Voucher::create($voucherData);

        $radiusService = app(FreeRadiusService::class);
        $radiusService->syncVoucherToRadius([
            'voucher_code' => $voucher->voucher_code,
            'password' => $voucher->password,
            'validity_hours' => $voucher->validity_hours,
            'validity_minutes' => $voucher->validity_minutes,
            'data_limit_mb' => $voucher->data_limit_mb,
            'speed_limit_kbps' => $voucher->speed_limit_kbps,
        ]);

        return $voucher;
    }

    private function generateVoucherCode(string $format = 'mixed', int $length = 8, array $existingCodes = []): string
    {
        $maxAttempts = 1000; // Increased from 100
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
                    // All letters - use random_int for better randomness
                    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
                    $code = '';
                    for ($i = 0; $i < $length; $i++) {
                        $code .= $chars[random_int(0, strlen($chars) - 1)];
                    }
                    break;
                case 'mixed':
                default:
                    // Mixed alphanumeric (exclude confusing chars: 0, O, I, 1)
                    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                    $code = '';
                    for ($i = 0; $i < $length; $i++) {
                        $code .= $chars[random_int(0, strlen($chars) - 1)];
                    }
                    break;
            }
            $attempts++;
            
        } while ((in_array($code, $existingCodes) || Voucher::where('voucher_code', $code)->exists()) && $attempts < $maxAttempts);

        if ($attempts >= $maxAttempts) {
            throw new \Exception('Failed to generate unique voucher code after ' . $maxAttempts . ' attempts. Try increasing code length.');
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
        $groupData = [
            'group_name' => $data['group_name'],
            'description' => $data['description'] ?? null,
            'profile_name' => $data['profile_name'],
            'validity_hours' => $data['validity_hours'],
            'validity_minutes' => $data['validity_minutes'] ?? null,
            'data_limit_mb' => $data['data_limit_mb'] ?? null,
            'speed_limit_kbps' => $data['speed_limit_kbps'] ?? null,
            'price' => $data['price'],
            'sales_point_id' => $data['sales_point_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'created_by' => $data['created_by'] ?? 'admin',
        ];

        $groupData = SiteScope::tenantCompatColumns('voucher_groups', $groupData);
        if (!Schema::connection('tenant')->hasColumn('voucher_groups', 'site_id')) {
            unset($groupData['site_id']);
        }
        if (!Schema::connection('tenant')->hasColumn('voucher_groups', 'validity_minutes')) {
            unset($groupData['validity_minutes']);
        }

        $group = VoucherGroup::create($groupData);

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
            
            // Use voucher code as both username and password for simplicity
            // Users only need to enter the voucher code once
            $vouchersData[] = [
                'voucher_code' => $code,
                'password' => $code,  // Same as voucher_code
                'group_id' => $group->id,
                'profile_name' => $group->profile_name,
                'validity_hours' => $group->validity_hours,
                'validity_minutes' => $group->validity_minutes ?? null,
                'data_limit_mb' => $group->data_limit_mb,
                'speed_limit_kbps' => $group->speed_limit_kbps,
                'price' => $group->price,
                'sales_point_id' => $group->sales_point_id,
                'site_id' => $group->site_id ?? null,
                'status' => 'unused',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $vouchersData[array_key_last($vouchersData)] = SiteScope::tenantCompatColumns('vouchers', $vouchersData[array_key_last($vouchersData)]);
            if (!Schema::connection('tenant')->hasColumn('vouchers', 'site_id')) {
                unset($vouchersData[array_key_last($vouchersData)]['site_id']);
            }
            if (!Schema::connection('tenant')->hasColumn('vouchers', 'validity_minutes')) {
                unset($vouchersData[array_key_last($vouchersData)]['validity_minutes']);
            }
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
            
            $syncResult = $radiusService->syncBatchToRadius($vouchers);
            $syncCount = $syncResult['synced'] ?? 0;
            
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
