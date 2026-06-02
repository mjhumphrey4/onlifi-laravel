<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Models\SystemSetting;
use App\Services\MikrotikService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientController extends Controller
{
    public function __construct(private MikrotikService $mikrotikService)
    {
    }

    public function index(Request $request)
    {
        $limit = $request->input('limit', 100);
        $site = SiteScope::selectedOrDefaultSite($request);
        $cacheKey = $this->clientsCacheKey($site?->id, (int) $limit);

        if ($request->boolean('refresh')) {
            $this->refreshFromRouter($request, true);
            Cache::forget($cacheKey);
        } elseif ($cached = Cache::get($cacheKey)) {
            $cached['cache']['source'] = 'cache';
            return response()->json($cached);
        }

        try {
            if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
                return response()->json([
                    'clients' => [],
                    'total' => 0,
                    'message' => 'Run tenant migrations to enable client telemetry storage.',
                ]);
            }

            $query = DB::connection('tenant')
                ->table('hotspot_users')
                ->select([
                    'id',
                    'mac_address',
                    'ip_address',
                    'username',
                    'device_type',
                    'uptime_seconds',
                    'data_uploaded_mb',
                    'data_downloaded_mb',
                    DB::raw('(data_uploaded_mb + data_downloaded_mb) as total_data_mb'),
                    'signal_strength',
                    'last_seen',
                    'router_name',
                    'voucher_code',
                    'profile_name',
                    'expires_at',
                    DB::raw('CASE WHEN last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN "online" ELSE "offline" END as status'),
                    DB::raw('COALESCE((SELECT SUM(amount) FROM transactions WHERE transactions.msisdn = hotspot_users.username), 0) as total_spent'),
                    DB::raw('(SELECT COUNT(*) FROM radacct WHERE radacct.callingstationid = hotspot_users.mac_address) as total_sessions'),
                ]);

            if ($site && Schema::connection('tenant')->hasColumn('hotspot_users', 'site_id')) {
                $query->where('site_id', $site->id);
            } elseif ($site && Schema::connection('tenant')->hasColumn('hotspot_users', 'router_name')) {
                $query->where('router_name', $site->name);
            }

            $clients = $query
                ->orderBy('last_seen', 'desc')
                ->limit($limit)
                ->get();

            $payload = [
                'clients' => $clients,
                'total' => $clients->count(),
                'refreshed_at' => $clients->max('last_seen'),
                'cache' => [
                    'source' => 'database',
                    'ttl_seconds' => 300,
                ],
            ];

            Cache::put($cacheKey, $payload, now()->addMinutes(5));

            return response()->json($payload);
        } catch (\Exception $e) {
            return response()->json([
                'clients' => [],
                'total' => 0,
                'message' => 'No client data available',
            ]);
        }
    }

    public function show($id)
    {
        $tenant = app('tenant');
        
        try {
            $client = DB::connection('tenant')
                ->table('hotspot_users')
                ->where('id', $id)
                ->first();

            if (!$client) {
                return response()->json([
                    'error' => 'Client not found',
                ], 404);
            }

            return response()->json($client);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch client',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function refresh(Request $request)
    {
        $this->refreshFromRouter($request, true);
        $site = SiteScope::selectedOrDefaultSite($request);
        Cache::forget($this->clientsCacheKey($site?->id, (int) $request->input('limit', 100)));
        return $this->index($request);
    }

    private function clientsCacheKey(?int $siteId, int $limit): string
    {
        $tenantId = app()->bound('tenant') ? app('tenant')->id : 'unknown';
        return "tenant:{$tenantId}:site:" . ($siteId ?: 'default') . ":clients:limit:{$limit}";
    }

    private function refreshFromRouter(Request $request, bool $force): void
    {
        if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
            return;
        }

        $site = SiteScope::selectedOrDefaultSite($request);
        if (!$site) {
            return;
        }

        if (!$force) {
            $latestSeen = DB::connection('tenant')
                ->table('hotspot_users')
                ->where('site_id', $site->id)
                ->max('last_seen');

            if ($latestSeen && now()->diffInMinutes($latestSeen) < 5) {
                return;
            }
        }

        $router = $this->resolveSiteRouter($site);
        if (!$router) {
            return;
        }

        $users = $this->mikrotikService->getActiveUsers($router);
        $now = now();
        $routerName = $router->name ?: $site->name;

        foreach ($users as $user) {
            $mac = strtoupper((string) ($user['mac_address'] ?? ''));
            if ($mac === '') {
                continue;
            }

            $username = $user['username'] ?: null;
            $voucher = $username
                ? DB::connection('tenant')->table('vouchers')->where('voucher_code', $username)->first()
                : null;

            DB::connection('tenant')->table('hotspot_users')->updateOrInsert(
                [
                    'site_id' => $site->id,
                    'mac_address' => $mac,
                ],
                [
                    'ip_address' => $user['ip_address'] ?: null,
                    'username' => $username,
                    'device_type' => 'HotSpot Client',
                    'uptime_seconds' => $this->parseMikrotikDuration((string) ($user['uptime'] ?? '0s')),
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
                ]
            );
        }
    }

    private function resolveSiteRouter($site): ?MikrotikRouter
    {
        $query = MikrotikRouter::query();
        if (Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
            $query->where('site_id', $site->id);
        }

        $existing = $query->where('is_active', true)->latest()->first();

        if ($site->vpn_private_ip) {
            return new MikrotikRouter([
                'name' => $site->name,
                'site_id' => $site->id,
                'ip_address' => $site->vpn_private_ip,
                'api_port' => $site->router_api_port ?: 8728,
                'username' => SystemSetting::get('router_admin_username', 'onlifi'),
                'password' => SystemSetting::get('router_admin_password', ''),
                'is_active' => true,
                'location' => $site->name,
            ]);
        }

        return $existing;
    }

    private function parseMikrotikDuration(string $duration): int
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
