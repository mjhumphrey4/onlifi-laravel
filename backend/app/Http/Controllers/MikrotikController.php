<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Models\InstallerDeviceSubmission;
use App\Models\RadiusNas;
use App\Models\RouterTelemetry;
use App\Models\Site;
use App\Models\SystemSetting;
use App\Services\MikrotikService;
use App\Services\RouterSnapshotService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MikrotikController extends Controller
{
    private $mikrotikService;

    public function __construct(MikrotikService $mikrotikService, private RouterSnapshotService $snapshots)
    {
        $this->mikrotikService = $mikrotikService;
    }

    public function index(Request $request)
    {
        $site = SiteScope::selectedSite($request);
        $query = MikrotikRouter::with('latestTelemetry');

        if ($site) {
            if (Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
                $query->where('site_id', $site->id);
            } else {
                $query->where('name', $site->name);
            }

            $routers = $query->orderBy('name')->orderBy('id')->get();
            if ($routers->isNotEmpty()) {
                return response()->json($this->decorateRouters($routers));
            }

            return response()->json([[
                'id' => null,
                'name' => $site->name,
                'site_id' => $site->id,
                'ip_address' => $site->vpn_private_ip,
                'api_port' => $site->router_api_port ?: 8728,
                'username' => SystemSetting::get('router_admin_username', 'onlifi'),
                'location' => $site->description,
                'is_active' => true,
                'last_seen' => $site->vpn_last_seen_at,
                'latest_telemetry' => null,
                'managed_by_site' => true,
                'needs_remote_access' => !$site->vpn_private_ip,
            ]]);
        }

        $routers = $query->orderBy('name')->get();

        return response()->json($this->decorateRouters($routers));
    }

    public function show($id)
    {
        $router = MikrotikRouter::with(['latestTelemetry', 'telemetry' => function($query) {
            $query->orderBy('recorded_at', 'desc')->limit(100);
        }])->findOrFail($id);

        return response()->json($router);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'ip_address' => 'required|ip|unique:mikrotik_routers',
            'api_port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:64',
            'password' => 'required|string',
            'location' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $request->all();
        $site = SiteScope::selectedSite($request);
        if ($site && Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
            $data['site_id'] = $site->id;
        }

        $router = MikrotikRouter::create($data);

        // Auto-register in RADIUS NAS table for multi-tenant authentication
        $tenant = app('tenant');
        if ($tenant) {
            RadiusNas::registerRouter($router, $tenant);
        }

        return response()->json($router, 201);
    }

    public function update(Request $request, $id)
    {
        $router = MikrotikRouter::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'ip_address' => 'sometimes|ip|unique:mikrotik_routers,ip_address,' . $id,
            'api_port' => 'sometimes|integer|min:1|max:65535',
            'username' => 'sometimes|string|max:64',
            'password' => 'sometimes|string',
            'location' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $router->update($request->all());

        return response()->json($router);
    }

    public function destroy($id)
    {
        $router = MikrotikRouter::findOrFail($id);
        $router->delete();

        return response()->json(['message' => 'Router deleted successfully']);
    }

    public function testConnection($id)
    {
        $router = MikrotikRouter::findOrFail($id);

        $connected = $this->mikrotikService->connect($router);

        if ($connected) {
            $this->mikrotikService->disconnect();
            return response()->json([
                'success' => true,
                'message' => 'Connection successful',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Connection failed',
        ], 500);
    }

    public function getActiveUsers($id)
    {
        $router = MikrotikRouter::findOrFail($id);
        if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
            return response()->json([]);
        }

        $query = DB::connection('tenant')->table('hotspot_users');
        if (Schema::connection('tenant')->hasColumn('hotspot_users', 'site_id') && $router->site_id) {
            $query->where('site_id', $router->site_id);
        } elseif (Schema::connection('tenant')->hasColumn('hotspot_users', 'router_name')) {
            $query->where('router_name', $router->name);
        }

        $users = $query
            ->where('last_seen', '>=', now()->subMinutes(6))
            ->orderByDesc('last_seen')
            ->get();

        return response()->json($users);
    }

    public function collectTelemetry($id)
    {
        $router = MikrotikRouter::findOrFail($id);
        $telemetry = $this->mikrotikService->collectTelemetry($router);

        if ($telemetry) {
            return response()->json($telemetry);
        }

        return response()->json([
            'error' => 'Failed to collect telemetry',
        ], 500);
    }

    public function ingestTelemetry(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'router_id' => 'nullable|integer',
            'router_name' => 'nullable|string',
            'router_identity' => 'nullable|string',
            'api_token' => 'nullable|string',
            'cpu_load' => 'nullable|numeric',
            'memory_used_mb' => 'nullable|integer',
            'memory_total_mb' => 'nullable|integer',
            'uptime_seconds' => 'nullable|integer',
            'active_connections' => 'nullable|integer',
            'total_clients' => 'nullable|integer',
            'bandwidth_upload_kbps' => 'nullable|numeric',
            'bandwidth_download_kbps' => 'nullable|numeric',
            'total_tx_bytes' => 'nullable|integer',
            'total_rx_bytes' => 'nullable|integer',
            'wan_interfaces' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Find router by ID, name, or identity (router_identity matches system identity name)
        $router = null;
        if ($request->router_id) {
            $router = MikrotikRouter::find($request->router_id);
        } elseif ($request->router_name) {
            $router = MikrotikRouter::where('name', $request->router_name)->first();
        } elseif ($request->router_identity) {
            $router = MikrotikRouter::where('name', $request->router_identity)->first();
        }

        // If router not found, try to auto-register it
        $routerName = $request->router_name ?? $request->router_identity;
        if (!$router && $routerName) {
            Log::info('Auto-registering new router from telemetry', ['name' => $routerName]);
            $router = MikrotikRouter::create([
                'name' => $routerName,
                'ip_address' => $request->ip() ?? '0.0.0.0',
                'is_active' => true,
            ]);
        }

        if (!$router) {
            return response()->json([
                'error' => 'Router not found. Provide router_id, router_name, or router_identity.',
            ], 404);
        }

        $router->update([
            'last_seen' => now(),
            'last_cpu_load' => $request->cpu_load,
            'last_memory_used_mb' => $request->memory_used_mb,
            'memory_total_mb' => $request->memory_total_mb,
            'last_active_connections' => $request->active_connections,
        ]);

        // Also store in central database for dashboard telemetry
        try {
            // Find site by router or get tenant context
            $site = null;
            $tenant = app('tenant');
            
            // Try to find a site for this router
            if ($router->site_id) {
                $site = Site::find($router->site_id);
            }
            
            // Build telemetry data for central DB
            $telemetryData = [
                'router_id' => $router->id,
                'site_id' => $site ? $site->id : null,
                'tenant_id' => $tenant ? $tenant->id : null,
                'router_identity' => $router->name,
                'router_version' => $request->router_version,
                'router_board' => $request->router_board,
                'cpu_load' => $request->cpu_load,
                'memory_used_mb' => $request->memory_used_mb,
                'memory_total_mb' => $request->memory_total_mb,
                'uptime_seconds' => $request->uptime_seconds,
                'active_connections' => $request->active_connections ?? $request->total_clients,
                'bandwidth_upload_kbps' => $request->bandwidth_upload_kbps,
                'bandwidth_download_kbps' => $request->bandwidth_download_kbps,
                'total_tx_bytes' => $request->total_tx_bytes,
                'total_rx_bytes' => $request->total_rx_bytes,
                'timestamp' => now(),
                'created_at' => now(),
            ];

            if (DB::connection('central')->getSchemaBuilder()->hasColumn('router_telemetry', 'wan_interfaces')) {
                $telemetryData['wan_interfaces'] = $request->wan_interfaces;
            }
            
            // Check if tenant_id column exists before including it
            $hasTenantIdColumn = DB::connection('central')
                ->getSchemaBuilder()
                ->hasColumn('router_telemetry', 'tenant_id');
                
            if (!$hasTenantIdColumn) {
                unset($telemetryData['tenant_id']);
            }
            
            DB::connection('central')->table('router_telemetry')->insert($telemetryData);
            
            Log::info('Telemetry stored in central database', [
                'router' => $router->name,
                'cpu_load' => $request->cpu_load,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store telemetry in central database', [
                'error' => $e->getMessage(),
                'router' => $router->name,
            ]);
            // Don't fail the request - telemetry was still stored in router table
        }

        return response()->json([
            'success' => true,
            'message' => 'Telemetry received',
            'router' => [
                'id' => $router->id,
                'name' => $router->name,
                'last_seen' => $router->last_seen,
            ],
        ]);
    }

    public function getRealtimeStats($id)
    {
        $router = MikrotikRouter::findOrFail($id);

        return response()->json([
            'router_id' => $router->id,
            'router_name' => $router->name,
            'cpu_load' => $router->last_cpu_load ?? 0,
            'memory_used_mb' => $router->last_memory_used_mb ?? 0,
            'memory_total_mb' => $router->memory_total_mb ?? 0,
            'active_connections' => $router->last_active_connections ?? 0,
            'last_seen' => $router->last_seen,
            'is_online' => $router->last_seen && $router->last_seen->diffInMinutes(now()) < 10,
        ]);
    }

    public function getAllActiveUsers()
    {
        $allUsers = Schema::connection('tenant')->hasTable('hotspot_users')
            ? DB::connection('tenant')
                ->table('hotspot_users')
                ->where('last_seen', '>=', now()->subMinutes(6))
                ->orderByDesc('last_seen')
                ->get()
            : collect();

        return response()->json([
            'total_active_users' => $allUsers->count(),
            'users' => $allUsers,
            'cached' => true,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function diagnostics(Request $request)
    {
        $site = SiteScope::selectedOrDefaultSite($request);
        $router = $this->resolveSiteRouter($request);

        if (!$site || !$router) {
            return response()->json([
                'ok' => false,
                'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
                'message' => 'Router remote access details are not configured for this site.',
            ]);
        }

        $connected = $this->mikrotikService->connect($router);
        $connectionError = $this->mikrotikService->getLastError();
        if ($connected) {
            $this->mikrotikService->disconnect();
        }

        $checks = [];
        foreach ([
            'hotspot_active' => fn () => $this->mikrotikService->getActiveUsers($router),
            'dhcp_leases' => fn () => $this->mikrotikService->getDhcpLeases($router),
            'dhcp_pools' => fn () => $this->mikrotikService->getDhcpPools($router),
            'system_users' => fn () => $this->mikrotikService->getSystemUsers($router),
            'ip_bindings' => fn () => $this->mikrotikService->getIpBindings($router),
        ] as $name => $callback) {
            $items = $connected ? $callback() : [];
            $checks[$name] = [
                'count' => count($items),
                'ok' => $connected && !$this->mikrotikService->getLastError(),
                'error' => $this->mikrotikService->getLastError(),
            ];
        }

        return response()->json([
            'ok' => $connected,
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'vpn_private_ip' => $site->vpn_private_ip,
                'router_api_port' => $site->router_api_port ?: 8728,
            ],
            'router' => [
                'host' => $router->ip_address,
                'api_port' => $router->api_port,
                'username' => $router->username,
            ],
            'connection' => [
                'ok' => $connected,
                'error' => $connectionError,
            ],
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function getIpBindings(Request $request)
    {
        if (!$request->boolean('refresh')) {
            $site = SiteScope::selectedOrDefaultSite($request);
            $cached = $site ? $this->snapshots->cachedRouterList($site, 'ip_bindings') : null;
            if ($cached) {
                return response()->json([
                    'bindings' => $cached['data'],
                    'cached' => true,
                    'last_synced_at' => $cached['last_synced_at'],
                ]);
            }

            return response()->json([
                'bindings' => [],
                'cached' => true,
                'last_synced_at' => null,
                'message' => 'Waiting for the background router snapshot job to populate IP bindings.',
            ]);
        }

        $router = $this->resolveSiteRouter($request);
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$router || !$site) {
            return response()->json([
                'bindings' => [],
                'message' => 'Router remote access details are not configured for this site.',
            ]);
        }

        $bindings = $this->mikrotikService->getIpBindings($router);

        $payload = [
            'bindings' => $bindings,
            'cached' => false,
            'last_synced_at' => now()->toIso8601String(),
        ];
        if (empty($bindings) && ($error = $this->mikrotikService->getLastError())) {
            $payload['message'] = $error;
            $payload['router_error'] = $error;
        } else {
            $this->snapshots->storeRouterListCache($site, 'ip_bindings', $bindings);
        }

        return response()->json($payload);
    }

    public function addIpBinding(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mac_address' => ['required', 'string', 'max:32'],
            'address' => ['nullable', 'ip'],
            'to_address' => ['nullable', 'ip'],
            'server' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:regular,bypassed,blocked'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $router = $this->resolveSiteRouter($request);

        if (!$router) {
            return response()->json([
                'error' => 'Router unavailable',
                'message' => 'Router remote access details are not configured for this site.',
            ], 422);
        }

        $created = $this->mikrotikService->addIpBinding($router, [
            'mac_address' => strtoupper($request->mac_address),
            'address' => $request->address,
            'to_address' => $request->to_address,
            'server' => $request->server ?: 'all',
            'type' => $request->type,
            'comment' => $request->comment,
        ]);

        if (!$created) {
            $message = $this->mikrotikService->getLastError()
                ?: 'Could not connect to the router or RouterOS rejected the binding.';

            return response()->json([
                'error' => 'Failed to add IP binding',
                'message' => $message,
            ], 422);
        }

        if ($site = SiteScope::selectedOrDefaultSite($request)) {
            $bindings = $this->mikrotikService->getIpBindings($router);
            if (!empty($bindings) || !$this->mikrotikService->getLastError()) {
                $this->snapshots->storeRouterListCache($site, 'ip_bindings', $bindings);
            }
        }

        return response()->json([
            'message' => 'IP binding added successfully.',
        ], 201);
    }

    public function getSystemUsers(Request $request)
    {
        if (!$request->boolean('refresh')) {
            $site = SiteScope::selectedOrDefaultSite($request);
            $cached = $site ? $this->snapshots->cachedRouterList($site, 'system_users') : null;
            if ($cached) {
                return response()->json([
                    'users' => $cached['data'],
                    'cached' => true,
                    'last_synced_at' => $cached['last_synced_at'],
                ]);
            }

            return response()->json([
                'users' => [],
                'cached' => true,
                'last_synced_at' => null,
                'message' => 'Waiting for the background router snapshot job to populate router users.',
            ]);
        }

        $router = $this->resolveSiteRouter($request);
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$router || !$site) {
            return response()->json([
                'users' => [],
                'message' => 'Router remote access details are not configured for this site.',
            ]);
        }

        $users = $this->mikrotikService->getSystemUsers($router);

        $payload = [
            'users' => $users,
            'cached' => false,
            'last_synced_at' => now()->toIso8601String(),
        ];
        if (empty($users) && ($error = $this->mikrotikService->getLastError())) {
            $payload['message'] = $error;
            $payload['router_error'] = $error;
        } else {
            $this->snapshots->storeRouterListCache($site, 'system_users', $users);
        }

        return response()->json($payload);
    }

    public function getDhcpLeases(Request $request)
    {
        if (!$request->boolean('refresh')) {
            $site = SiteScope::selectedOrDefaultSite($request);
            $cached = $site ? $this->snapshots->cachedRouterList($site, 'dhcp_leases') : null;
            if ($cached) {
                return response()->json([
                    'leases' => $cached['data'],
                    'cached' => true,
                    'last_synced_at' => $cached['last_synced_at'],
                ]);
            }

            return response()->json([
                'leases' => [],
                'cached' => true,
                'last_synced_at' => null,
                'message' => 'Waiting for the background router snapshot job to populate DHCP leases.',
            ]);
        }

        $router = $this->resolveSiteRouter($request);
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$router || !$site) {
            return response()->json([
                'leases' => [],
                'message' => 'Router remote access details are not configured for this site.',
            ]);
        }

        $leases = $this->mikrotikService->getDhcpLeases($router);

        $payload = [
            'leases' => $leases,
            'cached' => false,
            'last_synced_at' => now()->toIso8601String(),
        ];
        if (empty($leases) && ($error = $this->mikrotikService->getLastError())) {
            $payload['message'] = $error;
            $payload['router_error'] = $error;
        } else {
            $this->snapshots->storeRouterListCache($site, 'dhcp_leases', $leases);
        }

        return response()->json($payload);
    }

    public function getDhcpPools(Request $request)
    {
        if (!$request->boolean('refresh')) {
            $site = SiteScope::selectedOrDefaultSite($request);
            $cached = $site ? $this->snapshots->cachedRouterList($site, 'dhcp_pools') : null;
            if ($cached) {
                return response()->json([
                    'pools' => $cached['data'],
                    'cached' => true,
                    'last_synced_at' => $cached['last_synced_at'],
                ]);
            }

            return response()->json([
                'pools' => [],
                'cached' => true,
                'last_synced_at' => null,
                'message' => 'Waiting for the background router snapshot job to populate DHCP pools.',
            ]);
        }

        $router = $this->resolveSiteRouter($request);
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$router || !$site) {
            return response()->json([
                'pools' => [],
                'message' => 'Router remote access details are not configured for this site.',
            ]);
        }

        $pools = $this->mikrotikService->getDhcpPools($router);

        $payload = [
            'pools' => $pools,
            'cached' => false,
            'last_synced_at' => now()->toIso8601String(),
        ];
        if (empty($pools) && ($error = $this->mikrotikService->getLastError())) {
            $payload['message'] = $error;
            $payload['router_error'] = $error;
        } else {
            $this->snapshots->storeRouterListCache($site, 'dhcp_pools', $pools);
        }

        return response()->json($payload);
    }

    public function addSystemUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:255'],
            'group' => ['required', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $router = $this->resolveSiteRouter($request);

        if (!$router) {
            return response()->json([
                'error' => 'Router unavailable',
                'message' => 'Router remote access details are not configured for this site.',
            ], 422);
        }

        $created = $this->mikrotikService->addSystemUser($router, [
            'name' => $request->name,
            'password' => $request->password,
            'group' => $request->group,
            'comment' => $request->comment,
        ]);

        if (!$created) {
            return response()->json([
                'error' => 'Failed to add router user',
                'message' => 'Could not connect to the router or RouterOS rejected the user.',
            ], 500);
        }

        if ($site = SiteScope::selectedOrDefaultSite($request)) {
            $this->snapshots->storeRouterListCache($site, 'system_users', $this->mikrotikService->getSystemUsers($router));
        }

        return response()->json([
            'message' => 'Router user added successfully.',
        ], 201);
    }

    public function updateSystemUserStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id' => ['required', 'string', 'max:64'],
            'disabled' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $router = $this->resolveSiteRouter($request);

        if (!$router) {
            return response()->json([
                'error' => 'Router unavailable',
                'message' => 'Router remote access details are not configured for this site.',
            ], 422);
        }

        $updated = $this->mikrotikService->setSystemUserDisabled($router, $request->id, $request->boolean('disabled'));

        if (!$updated) {
            return response()->json([
                'error' => 'Failed to update router user',
                'message' => 'Could not connect to the router or RouterOS rejected the update.',
            ], 500);
        }

        if ($site = SiteScope::selectedOrDefaultSite($request)) {
            $this->snapshots->storeRouterListCache($site, 'system_users', $this->mikrotikService->getSystemUsers($router));
        }

        return response()->json([
            'message' => 'Router user updated successfully.',
        ]);
    }

    private function resolveSiteRouter(Request $request): ?MikrotikRouter
    {
        $site = SiteScope::selectedOrDefaultSite($request);

        if (!$site) {
            return null;
        }

        return $this->snapshots->routerForSite($site);
    }

    private function decorateRouters($routers)
    {
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        $routerIds = $routers->pluck('id')->filter()->values();
        $submissions = collect();

        if ($tenant && $routerIds->isNotEmpty()) {
            $submissions = InstallerDeviceSubmission::where('tenant_id', $tenant->id)
                ->whereIn('router_id', $routerIds)
                ->get()
                ->keyBy('router_id');
        }

        return $routers->map(function (MikrotikRouter $router) use ($submissions) {
            $data = $router->toArray();
            $submission = $submissions->get($router->id);

            $data['front_photo_url'] = $submission?->front_photo_path ? asset('storage/' . $submission->front_photo_path) : null;
            $data['back_photo_url'] = $submission?->back_photo_path ? asset('storage/' . $submission->back_photo_path) : null;
            $data['google_maps_url'] = ($router->latitude && $router->longitude)
                ? 'https://www.google.com/maps?q=' . $router->latitude . ',' . $router->longitude
                : null;
            $data['status'] = $router->last_seen && $router->last_seen->diffInMinutes(now()) < 10 ? 'online' : 'offline';

            return $data;
        })->values();
    }
}
