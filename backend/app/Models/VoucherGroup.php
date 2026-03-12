<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherGroup extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'voucher_type_id',
        'quantity',
        'generated_count',
        'status',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
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
