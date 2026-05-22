<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RouterTelemetry extends Model
{
    use HasFactory;

    protected $connection = 'central';

    protected $table = 'router_telemetry';

    protected $fillable = [
        'router_id',
        'site_id',
        'tenant_id',
        'router_identity',
        'router_version',
        'router_board',
        'cpu_load',
        'memory_used_mb',
        'memory_total_mb',
        'uptime_seconds',
        'active_connections',
        'bandwidth_upload_kbps',
        'bandwidth_download_kbps',
        'total_tx_bytes',
        'total_rx_bytes',
        'timestamp',
    ];

    protected $casts = [
        'router_id' => 'integer',
        'site_id' => 'integer',
        'tenant_id' => 'integer',
        'cpu_load' => 'decimal:2',
        'memory_used_mb' => 'integer',
        'memory_total_mb' => 'integer',
        'uptime_seconds' => 'integer',
        'active_connections' => 'integer',
        'bandwidth_upload_kbps' => 'decimal:10,2',
        'bandwidth_download_kbps' => 'decimal:10,2',
        'total_tx_bytes' => 'integer',
        'total_rx_bytes' => 'integer',
        'timestamp' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function router()
    {
        return $this->belongsTo(MikrotikRouter::class, 'router_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
