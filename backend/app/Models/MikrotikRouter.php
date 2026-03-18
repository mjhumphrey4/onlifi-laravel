<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MikrotikRouter extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $fillable = [
        'name',
        'ip_address',
        'api_port',
        'username',
        'password',
        'is_active',
        'last_seen',
        'location',
        'last_cpu_load',
        'last_memory_used_mb',
        'memory_total_mb',
        'last_active_connections',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'is_active' => 'boolean',
        'last_cpu_load' => 'decimal:2',
        'last_memory_used_mb' => 'integer',
        'memory_total_mb' => 'integer',
        'last_active_connections' => 'integer',
    ];


    public function telemetry()
    {
        return $this->hasMany(RouterTelemetry::class, 'router_id');
    }

    public function latestTelemetry()
    {
        return $this->hasOne(RouterTelemetry::class, 'router_id')->latestOfMany('recorded_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
