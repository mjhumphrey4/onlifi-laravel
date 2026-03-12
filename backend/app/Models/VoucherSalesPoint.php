<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherSalesPoint extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'location',
        'contact_person',
        'phone',
        'email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function vouchers()
    {
        return $this->hasMany(Voucher::class, 'sales_point_id');
    }

    public function voucherGroups()
    {
        return $this->hasMany(VoucherGroup::class, 'sales_point_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
