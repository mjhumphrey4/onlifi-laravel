<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptivePortalTemplate extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'name',
        'theme',
        'design',
        'is_active',
    ];

    protected $casts = [
        'design' => 'array',
        'is_active' => 'boolean',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
