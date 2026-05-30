<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'voucher_code',
        'password',
        'group_id',
        'profile_name',
        'validity_hours',
        'validity_minutes',
        'data_limit_mb',
        'speed_limit_kbps',
        'price',
        'sales_point_id',
        'site_id',
        'tenant_id',
        'status',
        'first_used_at',
        'last_used_at',
        'expires_at',
        'total_data_used_mb',
        'total_session_time_minutes',
        'last_accounting_at',
        'expired_reason',
        'created_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'validity_hours' => 'integer',
        'validity_minutes' => 'integer',
        'data_limit_mb' => 'integer',
        'speed_limit_kbps' => 'integer',
        'total_data_used_mb' => 'decimal:2',
        'total_session_time_minutes' => 'integer',
        'first_used_at' => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_accounting_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function group()
    {
        return $this->belongsTo(VoucherGroup::class, 'group_id');
    }

    public function salesPoint()
    {
        return $this->belongsTo(VoucherSalesPoint::class, 'sales_point_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'voucher_code', 'voucher_code');
    }

    public function scopeUnused($query)
    {
        return $query->where('status', 'unused');
    }

    public function scopeUsed($query)
    {
        return $query->where('status', 'used');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['unused', 'used'])
                     ->where(function($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }
}
