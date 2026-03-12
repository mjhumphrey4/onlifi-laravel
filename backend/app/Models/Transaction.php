<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'external_ref',
        'transaction_ref',
        'msisdn',
        'amount',
        'status',
        'status_message',
        'network_ref',
        'origin_site',
        'client_mac',
        'email',
        'voucher_code',
        'voucher_type',
        'origin_url',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeForSite($query, $site)
    {
        return $query->where('origin_site', $site);
    }
}
