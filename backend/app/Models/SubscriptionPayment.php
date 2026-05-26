<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'external_ref',
        'transaction_ref',
        'network_ref',
        'msisdn',
        'amount',
        'months',
        'status',
        'status_message',
        'narrative',
        'subscription_ends_at_before',
        'subscription_ends_at_after',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'months' => 'integer',
        'subscription_ends_at_before' => 'datetime',
        'subscription_ends_at_after' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
