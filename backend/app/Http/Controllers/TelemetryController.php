<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TelemetryController extends Controller
{
    public function getLatest(Request $request)
    {
        try {
            // Allow site_id filter from query parameter
            $siteId = $request->query('site_id');
            
            // Use central database connection for telemetry
            $query = DB::connection('central')->table('router_telemetry')
                ->select([
                    'router_identity',
                    'site_id',
                    DB::raw('MAX(id) as latest_id')
                ])
                ->groupBy('router_identity', 'site_id');
            
            // Filter by site_id if provided
            if ($siteId) {
                $query->where('site_id', $siteId);
            }
            
            $latestIds = $query->pluck('latest_id');
            
            $telemetry = DB::connection('central')->table('router_telemetry')
                ->whereIn('id', $latestIds)
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'telemetry' => $telemetry,
                'count' => $telemetry->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch telemetry', ['error' => $e->getMessage()]);
            return response()->json([
                'telemetry' => [],
                'count' => 0,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function getStats(Request $request)
    {
        try {
            $user = $request->user();
            
            Log::info('Fetching telemetry stats', [
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id
            ]);
            
            // Use central database connection for telemetry
            // Note: sites table doesn't have tenant_id, so we show all telemetry for authenticated users
            // TODO: Implement proper site-tenant relationship for filtering
            $query = DB::connection('central')->table('router_telemetry')
                ->select([
                    'router_identity',
                    'site_id',
                    DB::raw('MAX(id) as latest_id')
                ])
                ->groupBy('router_identity', 'site_id');
            
            $latestIds = $query->pluck('latest_id');
            
            Log::info('Found telemetry records', ['count' => $latestIds->count(), 'ids' => $latestIds->toArray()]);
            
            if ($latestIds->isEmpty()) {
                // No telemetry data found - return empty but valid response
                return response()->json([
                    'total_active_users' => 0,
                    'total_routers' => 0,
                    'online_routers' => 0,
                    'avg_cpu' => 0,
                    'avg_memory' => 0,
                    'routers' => [],
                    'timestamp' => now()->toIso8601String(),
                    'debug' => 'No telemetry records found'
                ]);
            }
            
            $routers = DB::connection('central')->table('router_telemetry')
                ->whereIn('id', $latestIds)
                ->get();
            
            $totalRouters = $routers->count();
            $onlineRouters = $routers->filter(function($r) {
                if (!$r->created_at) return false;
                try {
                    $createdAt = \Carbon\Carbon::parse($r->created_at);
                    return $createdAt->diffInMinutes(now()) < 10;
                } catch (\Exception $e) {
                    return false;
                }
            })->count();
            
            $totalActiveUsers = $routers->sum('active_connections') ?? 0;
            $avgCpu = $routers->avg('cpu_load') ?? 0;
            $avgMemory = 0;
            if ($routers->count() > 0) {
                $memoryPercentages = $routers->map(function($r) {
                    return $r->memory_total_mb > 0 ? ($r->memory_used_mb / $r->memory_total_mb) * 100 : 0;
                });
                $avgMemory = $memoryPercentages->avg() ?? 0;
            }
            
            $routerStats = $routers->map(function($r) {
                $isOnline = false;
                if ($r->created_at) {
                    try {
                        $createdAt = \Carbon\Carbon::parse($r->created_at);
                        $isOnline = $createdAt->diffInMinutes(now()) < 10;
                    } catch (\Exception $e) {
                        $isOnline = false;
                    }
                }
                return [
                    'id' => $r->id,
                    'name' => $r->router_identity ?? 'Unknown',
                    'location' => 'N/A',
                    'cpu_load' => $r->cpu_load ?? 0,
                    'memory_used_mb' => $r->memory_used_mb ?? 0,
                    'memory_total_mb' => $r->memory_total_mb ?? 0,
                    'active_users' => $r->active_connections ?? 0,
                    'last_seen' => $r->created_at,
                    'is_online' => $isOnline,
                    'uptime_seconds' => $r->uptime_seconds ?? 0,
                    'bandwidth_download_kbps' => $r->bandwidth_download_kbps ?? 0,
                    'bandwidth_upload_kbps' => $r->bandwidth_upload_kbps ?? 0,
                ];
            })->values();
            
            Log::info('Telemetry stats prepared', [
                'total_routers' => $totalRouters,
                'online_routers' => $onlineRouters,
                'total_active_users' => $totalActiveUsers,
            ]);
            
            return response()->json([
                'total_active_users' => $totalActiveUsers,
                'total_routers' => $totalRouters,
                'online_routers' => $onlineRouters,
                'avg_cpu' => round($avgCpu ?? 0, 2),
                'avg_memory' => round($avgMemory ?? 0, 2),
                'routers' => $routerStats,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get telemetry stats', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'total_active_users' => 0,
                'total_routers' => 0,
                'online_routers' => 0,
                'avg_cpu' => 0,
                'avg_memory' => 0,
                'routers' => [],
                'timestamp' => now()->toIso8601String(),
                'error' => $e->getMessage()
            ]);
        }
    }

    public function receive(Request $request)
    {
        // Log the incoming request for debugging
        Log::info('Telemetry received', [
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);

        // Extract Bearer token from Authorization header
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('Telemetry rejected: Missing or invalid Authorization header', [
                'headers' => $request->headers->all()
            ]);
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing or invalid Authorization header',
            ], 401);
        }

        $token = substr($authHeader, 7); // Remove "Bearer " prefix

        // Find site by API token
        $site = Site::where('api_token', $token)->first();
        if (!$site) {
            Log::warning('Telemetry rejected: Invalid API token', [
                'token_prefix' => substr($token, 0, 10) . '...',
                'all_sites' => Site::pluck('name', 'id')->toArray()
            ]);
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API token',
            ], 401);
        }

        Log::info('Telemetry authenticated successfully', [
            'site' => $site->name,
            'site_id' => $site->id,
        ]);

        // Store telemetry data in central database
        try {
            // Check if router_telemetry table exists in central database
            if (!DB::connection('central')->getSchemaBuilder()->hasTable('router_telemetry')) {
                Log::error('router_telemetry table does not exist in central database');
                return response()->json([
                    'error' => 'Configuration error',
                    'message' => 'Telemetry storage not configured',
                ], 500);
            }

            // Parse MikroTik timestamp format (e.g., "mar/26/2026 23:45:25")
            $timestamp = $request->input('timestamp');
            $parsedTimestamp = null;
            
            if ($timestamp) {
                try {
                    // Try to parse MikroTik format: "mar/26/2026 23:45:25"
                    $parsedTimestamp = \Carbon\Carbon::createFromFormat('M/d/Y H:i:s', $timestamp);
                } catch (\Exception $e) {
                    try {
                        // Try standard format
                        $parsedTimestamp = \Carbon\Carbon::parse($timestamp);
                    } catch (\Exception $e2) {
                        // Fall back to now
                        $parsedTimestamp = now();
                    }
                }
            } else {
                $parsedTimestamp = now();
            }

            $telemetryData = [
                'router_id' => null, // Will be null for now
                'site_id' => $site->id,
                'router_identity' => $request->input('router_identity', $request->input('router_name', 'unknown')),
                'router_version' => $request->input('router_version'),
                'router_board' => $request->input('router_board'),
                'cpu_load' => $request->input('cpu_load', 0),
                'memory_total_mb' => $request->input('memory_total_mb', 0),
                'memory_used_mb' => $request->input('memory_used_mb', 0),
                'uptime_seconds' => $request->input('uptime_seconds', 0),
                'active_connections' => $request->input('active_connections', 0),
                'bandwidth_download_kbps' => $request->input('bandwidth_download_kbps', 0),
                'bandwidth_upload_kbps' => $request->input('bandwidth_upload_kbps', 0),
                'total_tx_bytes' => $request->input('total_tx_bytes', 0),
                'total_rx_bytes' => $request->input('total_rx_bytes', 0),
                'timestamp' => $parsedTimestamp,
                'created_at' => now(),
            ];

            DB::connection('central')->table('router_telemetry')->insert($telemetryData);

            Log::info('Telemetry stored successfully', [
                'site' => $site->name,
                'router_identity' => $telemetryData['router_identity'],
                'cpu_load' => $telemetryData['cpu_load'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Telemetry data received successfully',
                'site' => $site->name,
                'router' => $telemetryData['router_identity'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store telemetry', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => 'Failed to store telemetry data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
