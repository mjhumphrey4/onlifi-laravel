<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IotecAuditLog extends Model
{
    protected $fillable = [
        'action',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
