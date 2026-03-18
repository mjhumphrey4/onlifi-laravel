<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Models\RadiusNas;
use App\Services\MikrotikService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MikrotikController extends Controller
{
    private $mikrotikService;

    public function __construct(MikrotikService $mikrotikService)
    {
        $this->mikrotikService = $mikrotikService;
    }

    public function index()
    {
        $routers = MikrotikRouter::with('latestTelemetry')
            ->orderBy('name')
            ->get();

        return response()->json($routers);
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

        $router = MikrotikRouter::create($request->all());

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
        $users = $this->mikrotikService->getActiveUsers($router);

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
            'router_id' => 'required|integer',
            'cpu_load' => 'nullable|numeric',
            'memory_used_mb' => 'nullable|integer',
            'memory_total_mb' => 'nullable|integer',
            'uptime_seconds' => 'nullable|integer',
            'active_connections' => 'nullable|integer',
            'total_clients' => 'nullable|integer',
            'bandwidth_upload_kbps' => 'nullable|numeric',
            'bandwidth_download_kbps' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $router = MikrotikRouter::find($request->router_id);

        if (!$router) {
            return response()->json([
                'error' => 'Router not found',
            ], 404);
        }

        $router->update([
            'last_seen' => now(),
            'last_cpu_load' => $request->cpu_load,
            'last_memory_used_mb' => $request->memory_used_mb,
            'last_active_connections' => $request->active_connections,
        ]);

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
        $routers = MikrotikRouter::where('is_active', true)->get();
        $allUsers = [];

        foreach ($routers as $router) {
            if ($this->mikrotikService->connect($router)) {
                $users = $this->mikrotikService->getActiveUsers($router);
                
                foreach ($users as $user) {
                    $allUsers[] = array_merge($user, [
                        'router_id' => $router->id,
                        'router_name' => $router->name,
                        'router_location' => $router->location,
                    ]);
                }
                
                $this->mikrotikService->disconnect();
            }
        }

        return response()->json([
            'total_active_users' => count($allUsers),
            'users' => $allUsers,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
