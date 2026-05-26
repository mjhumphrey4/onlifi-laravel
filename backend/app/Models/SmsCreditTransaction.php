<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsCreditTransaction extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'external_ref',
        'transaction_ref',
        'network_ref',
        'msisdn',
        'amount',
        'credits',
        'status',
        'status_message',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'credits' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
