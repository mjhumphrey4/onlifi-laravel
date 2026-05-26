<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VoucherType extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'type_name',
        'duration_hours',
        'base_amount',
        'description',
        'data_limit_mb',
        'speed_limit_kbps',
        'is_active',
        'tenant_id',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'duration_hours' => 'integer',
        'data_limit_mb' => 'integer',
        'speed_limit_kbps' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
