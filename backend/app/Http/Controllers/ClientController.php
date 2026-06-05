<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Services\MikrotikService;
use App\Services\RouterSnapshotService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class ClientController extends Controller
{
    public function __construct(private MikrotikService $mikrotikService, private RouterSnapshotService $snapshots)
    {
    }

    public function index(Request $request)
    {
        $limit = $request->input('limit', 100);
        $site = SiteScope::selectedOrDefaultSite($request);
        $cacheKey = $this->clientsCacheKey($site?->id, (int) $limit);
        $refreshResult = null;

        if ($request->boolean('refresh')) {
            $refreshResult = $this->refreshFromRouter($request, true);
            Cache::forget($cacheKey);
        } elseif ($cached = Cache::get($cacheKey)) {
            $cached['cache']['source'] = 'redis';
            return response()->json($cached);
        }

        try {
            $this->snapshots->ensureHotspotUsersTable();

            if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
                return response()->json([
                    'clients' => [],
                    'total' => 0,
                    'message' => 'Run tenant migrations to enable client telemetry storage.',
                ]);
            }

            $select = [
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
            ];

            if (Schema::connection('tenant')->hasColumn('hotspot_users', 'hostname')) {
                array_splice($select, 4, 0, ['hostname']);
            }

            $query = DB::connection('tenant')
                ->table('hotspot_users')
                ->select($select);

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
            if ($site && $clients->isEmpty()) {
                $payload['message'] = 'No cached client data is available yet. The background router snapshot job refreshes this every 5 minutes.';
            }
            if ($refreshResult && !$refreshResult['ok']) {
                $payload['message'] = $refreshResult['message'];
                $payload['router_error'] = $refreshResult['message'];
            }

            Cache::put($cacheKey, $payload, now()->addMinutes(5));

            return response()->json($payload);
        } catch (\Exception $e) {
            Log::warning('Failed to load active hotspot clients', [
                'site_id' => $site?->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'clients' => [],
                'total' => 0,
                'message' => 'No client data available. Check the router VPN/API path and server logs.',
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

    private function refreshFromRouter(Request $request, bool $force): array
    {
        if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
            $this->snapshots->ensureHotspotUsersTable();
            if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
                return ['ok' => false, 'message' => 'The hotspot_users table is not available for this tenant.'];
            }
        }

        $site = SiteScope::selectedOrDefaultSite($request);
        if (!$site) {
            return ['ok' => false, 'message' => 'No active site is selected.'];
        }

        if (!$force) {
            $latestSeenQuery = DB::connection('tenant')->table('hotspot_users');
            if (Schema::connection('tenant')->hasColumn('hotspot_users', 'site_id')) {
                $latestSeenQuery->where('site_id', $site->id);
            } elseif (Schema::connection('tenant')->hasColumn('hotspot_users', 'router_name')) {
                $latestSeenQuery->where('router_name', $site->name);
            }

            $latestSeen = $latestSeenQuery->max('last_seen');

            if ($latestSeen && now()->diffInMinutes(Carbon::parse($latestSeen)) < 5) {
                return ['ok' => true, 'message' => 'Using recent cached client data.'];
            }
        }

        $router = $this->resolveSiteRouter($site);
        if (!$router) {
            return ['ok' => false, 'message' => 'Router remote access details are not configured for this site.'];
        }

        $users = $this->mikrotikService->getActiveUsers($router);
        $activeError = $this->mikrotikService->getLastError();
        $leases = $this->mikrotikService->getDhcpLeases($router);
        $leaseError = $this->mikrotikService->getLastError();

        if (empty($users) && ($activeError || (empty($leases) && $leaseError))) {
            return [
                'ok' => false,
                'message' => $activeError ?: $leaseError ?: 'Router pull failed.',
            ];
        }

        $leasesByMac = collect($leases)
            ->mapWithKeys(function ($lease) {
                $mac = strtoupper((string) ($lease['mac_address'] ?? ''));
                return $mac === '' ? [] : [$mac => $lease];
            });

        $rows = [];
        $now = now();
        $routerName = $router->name ?: $site->name;
        $hasSiteId = Schema::connection('tenant')->hasColumn('hotspot_users', 'site_id');
        $hasHostname = Schema::connection('tenant')->hasColumn('hotspot_users', 'hostname');
        $hasVouchers = Schema::connection('tenant')->hasTable('vouchers');
        $seenMacs = [];

        foreach ($users as $user) {
            $mac = strtoupper((string) ($user['mac_address'] ?? ''));
            if ($mac === '' || isset($seenMacs[$mac])) {
                continue;
            }
            $seenMacs[$mac] = true;

            $lease = $leasesByMac->get($mac, []);

            $username = $user['username'] ?: null;
            $voucher = $username && $hasVouchers
                ? DB::connection('tenant')->table('vouchers')->where('voucher_code', $username)->first()
                : null;

            $values = [
                'mac_address' => $mac,
                'ip_address' => $user['ip_address'] ?: null,
                'username' => $username,
                'device_type' => $user['device_type'] ?? 'HotSpot Client',
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

        return [
            'ok' => true,
            'message' => 'Router clients refreshed.',
            'count' => count($rows),
        ];
    }

    private function resolveSiteRouter($site): ?MikrotikRouter
    {
        return $this->snapshots->routerForSite($site);
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
