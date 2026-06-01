<?php

namespace App\Observers;

use App\Models\Voucher;
use App\Services\RadiusService;
use Illuminate\Support\Facades\Log;

/**
 * VoucherObserver - Automatically syncs vouchers with FreeRADIUS
 * 
 * When vouchers are created, updated, or deleted, this observer
 * ensures the corresponding RADIUS entries are kept in sync.
 */
class VoucherObserver
{
    private $radiusService;

    public function __construct(RadiusService $radiusService)
    {
        $this->radiusService = $radiusService;
    }

    /**
     * Handle the Voucher "created" event.
     */
    public function created(Voucher $voucher): void
    {
        try {
            $this->radiusService->syncVoucher($voucher);
            Log::info('Voucher created and synced to RADIUS', ['voucher_code' => $voucher->voucher_code]);
        } catch (\Exception $e) {
            Log::error('Failed to sync new voucher to RADIUS', [
                'voucher_code' => $voucher->voucher_code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Voucher "updated" event.
     */
    public function updated(Voucher $voucher): void
    {
        try {
            // Finished/disabled vouchers must not remain in RADIUS.
            if (in_array($voucher->status, ['used', 'expired', 'disabled'])) {
                $this->radiusService->disableVoucher($voucher);
                Log::info('Voucher disabled in RADIUS', ['voucher_code' => $voucher->voucher_code]);
            } else {
                // Otherwise, sync the updated voucher
                $this->radiusService->syncVoucher($voucher);
                Log::info('Voucher updated and synced to RADIUS', ['voucher_code' => $voucher->voucher_code]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to sync updated voucher to RADIUS', [
                'voucher_code' => $voucher->voucher_code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Voucher "deleted" event.
     */
    public function deleted(Voucher $voucher): void
    {
        try {
            $this->radiusService->disableVoucher($voucher);
            Log::info('Voucher deleted from RADIUS', ['voucher_code' => $voucher->voucher_code]);
        } catch (\Exception $e) {
            Log::error('Failed to delete voucher from RADIUS', [
                'voucher_code' => $voucher->voucher_code,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
