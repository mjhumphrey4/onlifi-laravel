<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SmsWallet extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'credits',
    ];

    protected $casts = [
        'credits' => 'integer',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
