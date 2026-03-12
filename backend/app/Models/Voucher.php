<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'voucher_group_id',
        'voucher_type_id',
        'code',
        'username',
        'password',
        'status',
        'assigned_to',
        'assigned_at',
        'activated_at',
        'expires_at',
        'sales_point_id',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'activated_at' => 'datetime',
        'price' => 'decimal:2',
        'total_data_used_mb' => 'decimal:2',
        'first_used_at' => 'datetime',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
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
