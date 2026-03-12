<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RouterTelemetry extends Model
{
    use HasFactory;

    protected $connection = 'tenant';

    protected $table = 'router_telemetry';

    protected $fillable = [
        'router_id',
        'cpu_load',
        'memory_usage',
        'uptime',
        'active_users',
        'total_bandwidth_in',
        'total_bandwidth_out',
        'recorded_at',
    ];

    protected $casts = [
        'cpu_load' => 'decimal:2',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function router()
    {
        return $this->belongsTo(MikrotikRouter::class, 'router_id');
    }
}
