<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherTemplate extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'layout',
        'paper_size',
        'design',
        'logo_url',
        'background_color',
        'text_color',
        'accent_color',
        'show_voucher_code',
        'show_voucher_type',
        'show_sales_point',
        'show_duration',
        'show_price',
        'show_expiry',
        'show_qr_code',
        'header_text',
        'footer_text',
        'instructions',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'design' => 'array',
        'show_voucher_code' => 'boolean',
        'show_voucher_type' => 'boolean',
        'show_sales_point' => 'boolean',
        'show_duration' => 'boolean',
        'show_price' => 'boolean',
        'show_expiry' => 'boolean',
        'show_qr_code' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}
