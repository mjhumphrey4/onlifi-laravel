<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformFee extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'transaction_ref',
        'gross_amount',
        'platform_fee',
        'net_amount',
        'fee_percentage',
        'status',
        'yo_transaction_ref',
        'notes',
    ];

    protected $casts = [
        'gross_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'fee_percentage' => 'decimal:2',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Calculate and record platform fee for a transaction
     */
    public static function recordFee(
        int $tenantId,
        string $transactionRef,
        float $grossAmount,
        ?string $yoTransactionRef = null
    ): self {
        // Get current fee percentage from system settings
        $feePercentage = (float) SystemSetting::get('platform_collection_fee_percent', 5);
        
        $platformFee = round($grossAmount * ($feePercentage / 100), 2);
        $netAmount = $grossAmount - $platformFee;

        $record = self::create([
            'tenant_id' => $tenantId,
            'transaction_ref' => $transactionRef,
            'gross_amount' => $grossAmount,
            'platform_fee' => $platformFee,
            'net_amount' => $netAmount,
            'fee_percentage' => $feePercentage,
            'status' => 'collected',
            'yo_transaction_ref' => $yoTransactionRef,
        ]);

        // Update daily revenue summary
        self::updateDailyRevenue($grossAmount, $platformFee);

        return $record;
    }

    /**
     * Update daily platform revenue summary
     */
    private static function updateDailyRevenue(float $grossAmount, float $platformFee): void
    {
        $today = now()->toDateString();
        
        PlatformRevenue::updateOrCreate(
            ['date' => $today],
            [
                'total_collections' => \DB::raw("total_collections + {$grossAmount}"),
                'total_fees' => \DB::raw("total_fees + {$platformFee}"),
                'transaction_count' => \DB::raw("transaction_count + 1"),
            ]
        );
    }

    /**
     * Get tenant's balance (net amount after fees)
     */
    public static function getTenantBalance(int $tenantId): float
    {
        return self::where('tenant_id', $tenantId)
            ->where('status', 'collected')
            ->sum('net_amount');
    }

    /**
     * Get platform's total fees collected
     */
    public static function getTotalPlatformFees(): float
    {
        return self::where('status', 'collected')->sum('platform_fee');
    }
}
