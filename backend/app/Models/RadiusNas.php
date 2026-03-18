<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RadiusNas extends Model
{
    protected $connection = 'central';
    
    protected $table = 'nas';

    protected $fillable = [
        'nasname',
        'shortname',
        'type',
        'ports',
        'secret',
        'server',
        'community',
        'description',
        'tenant_id',
    ];

    protected $hidden = [
        'secret',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Generate a secure RADIUS secret
     */
    public static function generateSecret(): string
    {
        return Str::random(32);
    }

    /**
     * Register a MikroTik router as a RADIUS client
     */
    public static function registerRouter(MikrotikRouter $router, Tenant $tenant): self
    {
        return self::updateOrCreate(
            ['nasname' => $router->ip_address],
            [
                'shortname' => $router->name,
                'type' => 'other',
                'secret' => $tenant->radius_secret ?? self::generateSecret(),
                'description' => "Tenant: {$tenant->name} - Router: {$router->name}",
                'tenant_id' => $tenant->id,
            ]
        );
    }
}
