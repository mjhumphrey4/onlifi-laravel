<?php

namespace App\Services;

use App\Models\MikrotikRouter;
use App\Models\Site;
use App\Models\SystemSetting;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RouterSnapshotService
{
    public function __construct(private MikrotikService $mikrotikService)
    {
    }

    public function routerForSite(Site $site): ?MikrotikRouter
    {
        if ($site->vpn_private_ip) {
            return new MikrotikRouter([
                'name' => $site->name,
                'site_id' => $site->id,
                'ip_address' => $site->vpn_private_ip,
                'api_port' => $site->router_api_port ?: 8728,
                'username' => SystemSetting::get('router_admin_username', 'onlifi'),
                'password' => SystemSetting::get('router_admin_password', 'onlifi-router-admin-change-me'),
                'is_active' => true,
                'location' => $site->name,
            ]);
        }

        if (!Schema::connection('tenant')->hasTable('mikrotik_routers')) {
            return null;
        }

        $query = MikrotikRouter::query()->where('is_active', true);
        if (Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
            $query->where('site_id', $site->id);
        } else {
            $query->where('name', $site->name);
        }

        return $query->latest()->first();
    }

    public function syncSite(Site $site, ?array $only = null): array
    {
        $router = $this->routerForSite($site);
        if (!$router) {
            return ['ok' => false, 'message' => 'Router remote access details are not configured.'];
        }

        $types = $only ?: ['hotspot_users', 'ip_bindings', 'system_users', 'dhcp_leases', 'dhcp_pools', 'pppoe_clients'];
        $result = ['ok' => true, 'router' => $router->ip_address, 'synced' => []];

        if (in_array('hotspot_users', $types, true)) {
            $result['synced']['hotspot_users'] = $this->syncHotspotUsers($site, $router);
        }
        if (in_array('ip_bindings', $types, true)) {
            $result['synced']['ip_bindings'] = $this->syncIpBindings($site, $router);
        }
        if (in_array('system_users', $types, true)) {
            $result['synced']['system_users'] = $this->syncSystemUsers($site, $router);
        }
        if (in_array('dhcp_leases', $types, true)) {
            $result['synced']['dhcp_leases'] = $this->syncDhcpLeases($site, $router);
        }
        if (in_array('dhcp_pools', $types, true)) {
            $result['synced']['dhcp_pools'] = $this->syncDhcpPools($site, $router);
        }
        if (in_array('pppoe_clients', $types, true)) {
            $result['synced']['pppoe_clients'] = $this->syncPppoeClients($site, $router);
        }

        return $result;
    }

    public function syncForTelemetrySite(Site $site): void
    {
        try {
            $tenant = Tenant::find($site->tenant_id);
            if (!$tenant) {
                return;
            }

            $tenant->configure();
            $this->syncSite($site);
        } catch (\Throwable $e) {
            Log::warning('Router snapshot sync after telemetry failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function syncHotspotUsers(Site $site, MikrotikRouter $router): int
    {
        $this->ensureHotspotUsersTable();
        $users = $this->mikrotikService->getActiveUsers($router);
        $activeError = $this->mikrotikService->getLastError();
        if (empty($users) && $activeError) {
            return 0;
        }

        $leasesByMac = collect($this->mikrotikService->getDhcpLeases($router))
            ->mapWithKeys(function ($lease) {
                $mac = strtoupper((string) ($lease['mac_address'] ?? ''));
                return $mac === '' ? [] : [$mac => $lease];
            });

        $now = now();
        $rows = [];
        $hasSiteId = Schema::connection('tenant')->hasColumn('hotspot_users', 'site_id');
        $hasHostname = Schema::connection('tenant')->hasColumn('hotspot_users', 'hostname');
        $routerName = $router->name ?: $site->name;
        $seenMacs = [];

        foreach ($users as $user) {
            $mac = strtoupper((string) ($user['mac_address'] ?? ''));
            if ($mac === '' || isset($seenMacs[$mac])) {
                continue;
            }
            $seenMacs[$mac] = true;

            $lease = $leasesByMac->get($mac, []);
            $username = $user['username'] ?: null;
            $voucher = $username && Schema::connection('tenant')->hasTable('vouchers')
                ? DB::connection('tenant')->table('vouchers')->where('voucher_code', $username)->first()
                : null;

            $values = [
                'mac_address' => $mac,
                'ip_address' => $user['ip_address'] ?: null,
                'username' => $username,
                'device_type' => $user['device_type'] ?? 'HotSpot Client',
                'uptime_seconds' => $this->parseRouterDuration((string) ($user['uptime'] ?? '0s')),
                'data_uploaded_mb' => round(((float) ($user['bytes_in'] ?? 0)) / 1048576, 2),
                'data_downloaded_mb' => round(((float) ($user['bytes_out'] ?? 0)) / 1048576, 2),
                'signal_strength' => null,
                'last_seen' => $now,
                'router_name' => $routerName,
                'router_identity' => $routerName,
                'voucher_code' => $voucher?->voucher_code,
                'profile_name' => $voucher?->profile_name,
                'expires_at' => $voucher?->expires_at,
                'updated_at' => $now,
                'created_at' => $now,
            ];
            if ($hasSiteId) {
                $values['site_id'] = $site->id;
            }
            if ($hasHostname) {
                $values['hostname'] = $lease['hostname'] ?? '';
            }
            if (empty($values['ip_address']) && !empty($lease['ip_address'])) {
                $values['ip_address'] = $lease['ip_address'];
            }

            $rows[] = $values;
        }

        DB::connection('tenant')->transaction(function () use ($site, $routerName, $hasSiteId, $rows) {
            $delete = DB::connection('tenant')->table('hotspot_users');

            if ($hasSiteId) {
                $delete->where('site_id', $site->id);
            } elseif (Schema::connection('tenant')->hasColumn('hotspot_users', 'router_name')) {
                $delete->where('router_name', $routerName);
            }

            $delete->delete();

            if (!empty($rows)) {
                DB::connection('tenant')->table('hotspot_users')->insert($rows);
            }
        });

        return count($rows);
    }

    public function syncIpBindings(Site $site, MikrotikRouter $router): int
    {
        $bindings = $this->mikrotikService->getIpBindings($router);
        if (empty($bindings) && $this->mikrotikService->getLastError()) {
            return 0;
        }
        $this->storeRouterListCache($site, 'ip_bindings', $bindings);
        return count($bindings);
    }

    public function syncSystemUsers(Site $site, MikrotikRouter $router): int
    {
        $users = $this->mikrotikService->getSystemUsers($router);
        if (empty($users) && $this->mikrotikService->getLastError()) {
            return 0;
        }
        $this->storeRouterListCache($site, 'system_users', $users);
        return count($users);
    }

    public function syncDhcpLeases(Site $site, MikrotikRouter $router): int
    {
        $leases = $this->mikrotikService->getDhcpLeases($router);
        if (empty($leases) && $this->mikrotikService->getLastError()) {
            return 0;
        }
        $this->storeRouterListCache($site, 'dhcp_leases', $leases);
        return count($leases);
    }

    public function syncDhcpPools(Site $site, MikrotikRouter $router): int
    {
        $pools = $this->mikrotikService->getDhcpPools($router);
        if (empty($pools) && $this->mikrotikService->getLastError()) {
            return 0;
        }
        $this->storeRouterListCache($site, 'dhcp_pools', $pools);
        return count($pools);
    }

    public function syncPppoeClients(Site $site, MikrotikRouter $router): int
    {
        $this->ensurePppoeClientsTable();
        $secrets = $this->mikrotikService->getPppoeSecrets($router);
        $now = now();

        foreach ($secrets as $secret) {
            $username = $secret['username'] ?: $secret['name'];
            if (!$username) {
                continue;
            }

            DB::connection('tenant')->table('pppoe_clients')->updateOrInsert(
                [
                    'site_id' => $site->id,
                    'username' => $username,
                ],
                [
                    'router_id' => $secret['id'] ?: null,
                    'name' => $secret['name'] ?: $username,
                    'password' => $secret['password'] ?: null,
                    'profile' => $secret['profile'] ?: null,
                    'service' => $secret['service'] ?: 'pppoe',
                    'remote_address' => $secret['remote_address'] ?: null,
                    'notes' => $secret['comment'] ?: null,
                    'is_active' => !$secret['disabled'],
                    'last_seen_at' => $secret['last_logged_out'] ?: null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return count($secrets);
    }

    public function cachedRouterList(Site $site, string $type): ?array
    {
        $cacheKey = $this->routerCacheKey($site, $type);
        if ($cached = Cache::get($cacheKey)) {
            $cached['source'] = 'redis';
            return $cached;
        }

        $this->ensureRouterCacheTable();

        $row = DB::connection('tenant')->table('router_cached_lists')
            ->where('site_id', $site->id)
            ->where('type', $type)
            ->first();

        if (!$row) {
            return null;
        }

        $payload = [
            'data' => json_decode($row->payload ?: '[]', true) ?: [],
            'last_synced_at' => $row->last_synced_at,
            'source' => 'database',
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(10));

        return $payload;
    }

    public function storeRouterListCache(Site $site, string $type, array $payload): void
    {
        $cachePayload = [
            'data' => $payload,
            'last_synced_at' => now()->toIso8601String(),
            'source' => 'router',
        ];

        Cache::put($this->routerCacheKey($site, $type), $cachePayload, now()->addMinutes(10));

        $this->ensureRouterCacheTable();

        DB::connection('tenant')->table('router_cached_lists')->updateOrInsert(
            [
                'site_id' => $site->id,
                'type' => $type,
            ],
            [
                'payload' => json_encode($payload),
                'last_synced_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function routerCacheKey(Site $site, string $type): string
    {
        return "tenant:{$site->tenant_id}:site:{$site->id}:router:list:{$type}";
    }

    public function ensureRouterCacheTable(): void
    {
        if (Schema::connection('tenant')->hasTable('router_cached_lists')) {
            return;
        }

        Schema::connection('tenant')->create('router_cached_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('type', 64);
            $table->longText('payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'type']);
        });
    }

    public function ensurePppoeClientsTable(): void
    {
        if (!Schema::connection('tenant')->hasTable('pppoe_clients')) {
            Schema::connection('tenant')->create('pppoe_clients', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('router_id', 64)->nullable();
                $table->string('name', 100);
                $table->string('username', 100);
                $table->string('password')->nullable();
                $table->string('profile', 100)->nullable();
                $table->string('service', 100)->nullable();
                $table->string('remote_address', 64)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('notes', 255)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->unique(['site_id', 'username']);
            });
            return;
        }

        if (!Schema::connection('tenant')->hasColumn('pppoe_clients', 'router_id')) {
            Schema::connection('tenant')->table('pppoe_clients', function (Blueprint $table) {
                $table->string('router_id', 64)->nullable()->after('site_id');
            });
        }
    }

    public function ensureHotspotUsersTable(): void
    {
        if (Schema::connection('tenant')->hasTable('hotspot_users')) {
            if (!Schema::connection('tenant')->hasColumn('hotspot_users', 'hostname')) {
                Schema::connection('tenant')->table('hotspot_users', function (Blueprint $table) {
                    $table->string('hostname', 100)->nullable()->after('username');
                });
            }
            return;
        }

        Schema::connection('tenant')->create('hotspot_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('mac_address', 32)->index();
            $table->string('ip_address', 64)->nullable();
            $table->string('username', 100)->nullable()->index();
            $table->string('hostname', 100)->nullable();
            $table->string('device_type', 100)->nullable();
            $table->unsignedInteger('uptime_seconds')->default(0);
            $table->decimal('data_uploaded_mb', 12, 2)->default(0);
            $table->decimal('data_downloaded_mb', 12, 2)->default(0);
            $table->string('signal_strength', 32)->nullable();
            $table->timestamp('last_seen')->nullable()->index();
            $table->string('router_name', 100)->nullable();
            $table->string('router_identity', 100)->nullable();
            $table->string('voucher_code', 100)->nullable();
            $table->string('profile_name', 100)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'mac_address']);
        });
    }

    private function parseRouterDuration(string $duration): int
    {
        preg_match_all('/(\d+)(w|d|h|m|s)/', $duration, $matches, PREG_SET_ORDER);
        $seconds = 0;

        foreach ($matches as $match) {
            $value = (int) $match[1];
            $seconds += match ($match[2]) {
                'w' => $value * 604800,
                'd' => $value * 86400,
                'h' => $value * 3600,
                'm' => $value * 60,
                default => $value,
            };
        }

        return $seconds;
    }
}
