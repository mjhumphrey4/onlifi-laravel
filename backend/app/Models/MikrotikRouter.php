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
        'host',
        'port',
        'username',
        'password',
        'status',
        'last_seen',
        'firmware_version',
        'model',
        'location',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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
