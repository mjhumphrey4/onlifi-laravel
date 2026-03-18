<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformRevenue extends Model
{
    protected $connection = 'central';
    
    protected $table = 'platform_revenue';

    protected $fillable = [
        'date',
        'total_collections',
        'total_fees',
        'total_disbursed',
        'transaction_count',
    ];

    protected $casts = [
        'date' => 'date',
        'total_collections' => 'decimal:2',
        'total_fees' => 'decimal:2',
        'total_disbursed' => 'decimal:2',
    ];

    /**
     * Get revenue summary for a date range
     */
    public static function getSummary(string $startDate = null, string $endDate = null): array
    {
        $query = self::query();

        if ($startDate) {
            $query->where('date', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('date', '<=', $endDate);
        }

        return [
            'total_collections' => $query->sum('total_collections'),
            'total_fees' => $query->sum('total_fees'),
            'total_disbursed' => $query->sum('total_disbursed'),
            'transaction_count' => $query->sum('transaction_count'),
            'days' => $query->count(),
        ];
    }

    /**
     * Get today's revenue
     */
    public static function getToday(): ?self
    {
        return self::where('date', now()->toDateString())->first();
    }
}
