<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallerDeviceSubmission extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'installer_user_id',
        'router_id',
        'local_id',
        'device_name',
        'ip_address',
        'latitude',
        'longitude',
        'front_photo_path',
        'back_photo_path',
        'notes',
        'mobile_created_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'mobile_created_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function installer()
    {
        return $this->belongsTo(TenantUser::class, 'installer_user_id');
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }
}
