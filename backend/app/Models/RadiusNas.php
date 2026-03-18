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
        'router_identifier',
        'shortname',
        'type',
        'ports',
        'secret',
        'server',
        'community',
        'description',
        'tenant_id',
        'router_id',
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
     * Generate a unique router identifier for RADIUS
     * Format: ONLIFI-{tenant_id}-{router_id}-{random}
     */
    public static function generateRouterIdentifier(int $tenantId, int $routerId): string
    {
        return sprintf('ONLIFI-%d-%d-%s', $tenantId, $routerId, strtoupper(Str::random(8)));
    }

    /**
     * Register a MikroTik router as a RADIUS client
     * Uses router_identifier instead of IP since routers often have dynamic IPs
     */
    public static function registerRouter(MikrotikRouter $router, Tenant $tenant): self
    {
        // Check if already registered
        $existing = self::where('tenant_id', $tenant->id)
            ->where('router_id', $router->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Get or create the global RADIUS secret (shared across all routers)
        $globalSecret = SystemSetting::get('radius_shared_secret');
        if (!$globalSecret) {
            $globalSecret = self::generateSecret();
            SystemSetting::set('radius_shared_secret', $globalSecret, 'security', 'Shared RADIUS secret for all routers');
        }

        return self::create([
            'nasname' => $router->ip_address ?: 'dynamic',
            'router_identifier' => self::generateRouterIdentifier($tenant->id, $router->id),
            'shortname' => Str::slug($router->name),
            'type' => 'other',
            'secret' => $globalSecret,
            'description' => "Tenant: {$tenant->name} - Router: {$router->name}",
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
        ]);
    }

    /**
     * Find tenant by router identifier (used by FreeRADIUS)
     */
    public static function findTenantByRouterIdentifier(string $identifier): ?Tenant
    {
        $nas = self::where('router_identifier', $identifier)->first();
        return $nas ? $nas->tenant : null;
    }
}
