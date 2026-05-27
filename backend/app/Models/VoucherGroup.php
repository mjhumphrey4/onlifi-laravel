<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherGroup extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'group_name',
        'description',
        'profile_name',
        'validity_hours',
        'data_limit_mb',
        'speed_limit_kbps',
        'price',
        'sales_point_id',
        'site_id',
        'tenant_id',
        'created_by',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'validity_hours' => 'integer',
        'data_limit_mb' => 'integer',
        'speed_limit_kbps' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function vouchers()
    {
        return $this->hasMany(Voucher::class, 'group_id');
    }

    public function salesPoint()
    {
        return $this->belongsTo(VoucherSalesPoint::class, 'sales_point_id');
    }
}
