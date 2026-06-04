<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Services\RouterSnapshotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TelemetryController extends Controller
{
    private const ONLINE_WINDOW_MINUTES = 2;

    public function getLatest(Request $request)
    {
        try {
            $user = $request->user();
            
            // Use central database connection for telemetry
            $query = DB::connection('central')->table('router_telemetry')
                ->select([
                    'router_identity',
                    'site_id',
                    DB::raw('MAX(id) as latest_id')
                ])
                ->groupBy('router_identity', 'site_id');
            
            // CRITICAL: Filter telemetry by the authenticated user's tenant
            // so users only see their own routers' data
            // Check if tenant_id column exists before filtering
            $hasTenantIdColumn = DB::connection('central')
                ->getSchemaBuilder()
                ->hasColumn('router_telemetry', 'tenant_id');
            
            if ($hasTenantIdColumn && $user && isset($user->tenant_id) && $user->tenant_id) {
                $query->where('tenant_id', $user->tenant_id);
            }
            if ($request->header('X-Site-ID') && is_numeric($request->header('X-Site-ID'))) {
                $query->where('site_id', (int) $request->header('X-Site-ID'));
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
                'user_id' => $user?->id,
                'tenant_id' => $user?->tenant_id ?? null,
            ]);
            
            // Use central database connection for telemetry
            // Filter by the authenticated user's tenant_id so users only see
            // telemetry data for their own routers
            $query = DB::connection('central')->table('router_telemetry')
                ->select([
                    'router_identity',
                    'site_id',
                    DB::raw('MAX(id) as latest_id')
                ])
                ->groupBy('router_identity', 'site_id');
            
            // Check if tenant_id column exists before filtering
            $hasTenantIdColumn = DB::connection('central')
                ->getSchemaBuilder()
                ->hasColumn('router_telemetry', 'tenant_id');
            
            if ($hasTenantIdColumn && $user && isset($user->tenant_id) && $user->tenant_id) {
                $query->where('tenant_id', $user->tenant_id);
            }
            if ($request->header('X-Site-ID') && is_numeric($request->header('X-Site-ID'))) {
                $query->where('site_id', (int) $request->header('X-Site-ID'));
            }
            
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
            
            $previousSamples = $this->getPreviousTelemetrySamples($routers);

            $totalRouters = $routers->count();
            $onlineRouters = $routers->filter(function($r) {
                if (!$r->created_at) return false;
                try {
                    $createdAt = \Carbon\Carbon::parse($r->created_at);
                    return $createdAt->diffInMinutes(now()) < self::ONLINE_WINDOW_MINUTES;
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
            
            $routerStats = $routers->map(function($r) use ($previousSamples) {
                $isOnline = false;
                if ($r->created_at) {
                    try {
                        $createdAt = \Carbon\Carbon::parse($r->created_at);
                        $isOnline = $createdAt->diffInMinutes(now()) < self::ONLINE_WINDOW_MINUTES;
                    } catch (\Exception $e) {
                        $isOnline = false;
                    }
                }
                $rates = $this->calculateBandwidthRates($r, $previousSamples[$r->id] ?? null);
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
                    'bandwidth_download_kbps' => $rates['download'],
                    'bandwidth_upload_kbps' => $rates['upload'],
                    'total_tx_bytes' => $r->total_tx_bytes ?? 0,
                    'total_rx_bytes' => $r->total_rx_bytes ?? 0,
                ];
            })->values();

            $resourceTrend = $this->resourceTrend($request);
            
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
                'bandwidth_download_kbps' => round($routerStats->sum('bandwidth_download_kbps'), 2),
                'bandwidth_upload_kbps' => round($routerStats->sum('bandwidth_upload_kbps'), 2),
                'routers' => $routerStats,
                'resource_trend' => $resourceTrend,
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

    public function getUsage(Request $request)
    {
        try {
            if (!DB::connection('central')->getSchemaBuilder()->hasTable('router_telemetry')) {
                return response()->json($this->emptyUsageResponse($request->query('period', 'today')));
            }

            [$period, $start, $end] = $this->usageWindow((string) $request->query('period', 'today'));
            $query = DB::connection('central')->table('router_telemetry')
                ->whereBetween('created_at', [$start->copy()->subDay(), $end])
                ->orderBy('site_id')
                ->orderBy('router_identity')
                ->orderBy('created_at');

            $this->applyTelemetryScope($query, $request);

            $rows = $query->get();
            $previousByRouter = [];
            $downloadBytes = 0;
            $uploadBytes = 0;
            $sampleCount = 0;
            $wanInterfaces = [];

            foreach ($rows as $row) {
                $key = ($row->site_id ?? 'none') . '|' . ($row->router_identity ?? 'unknown');
                $createdAt = \Carbon\Carbon::parse($row->created_at);
                $previous = $previousByRouter[$key] ?? null;

                if ($previous && $createdAt->greaterThanOrEqualTo($start) && $createdAt->lessThanOrEqualTo($end)) {
                    $seconds = max(0, \Carbon\Carbon::parse($previous->created_at)->diffInSeconds($createdAt));
                    $rxDelta = max(0, (int) ($row->total_rx_bytes ?? 0) - (int) ($previous->total_rx_bytes ?? 0));
                    $txDelta = max(0, (int) ($row->total_tx_bytes ?? 0) - (int) ($previous->total_tx_bytes ?? 0));

                    if ($seconds > 0 && $this->isReasonableWanDelta($rxDelta, $txDelta, $seconds)) {
                        $downloadBytes += $rxDelta;
                        $uploadBytes += $txDelta;
                        $sampleCount++;
                    }
                }

                if (!empty($row->wan_interfaces)) {
                    foreach (explode(',', $row->wan_interfaces) as $interface) {
                        $interface = trim($interface);
                        if ($interface !== '') {
                            $wanInterfaces[$interface] = true;
                        }
                    }
                }

                $previousByRouter[$key] = $row;
            }

            return response()->json([
                'period' => $period,
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'download_bytes' => $downloadBytes,
                'upload_bytes' => $uploadBytes,
                'total_bytes' => $downloadBytes + $uploadBytes,
                'sample_count' => $sampleCount,
                'wan_interfaces' => array_keys($wanInterfaces),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to calculate telemetry usage', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to calculate telemetry usage',
                'message' => $e->getMessage(),
            ], 500);
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
                'tenant_id' => $site->tenant_id, // Link telemetry to the tenant who owns the site
                'router_identity' => $request->input('router_identity', $request->input('router_name', 'unknown')),
                'router_version' => $request->input('router_version'),
                'router_board' => $request->input('router_board'),
                'cpu_load' => $request->input('cpu_load', 0),
                'memory_total_mb' => $request->input('memory_total_mb', 0),
                'memory_used_mb' => $request->input('memory_used_mb', 0),
                'uptime_seconds' => $this->uptimeSecondsFromRequest($request),
                'active_connections' => $request->input('active_connections', 0),
                'bandwidth_download_kbps' => $request->input('bandwidth_download_kbps', 0),
                'bandwidth_upload_kbps' => $request->input('bandwidth_upload_kbps', 0),
                'total_tx_bytes' => $request->input('total_tx_bytes', 0),
                'total_rx_bytes' => $request->input('total_rx_bytes', 0),
                'timestamp' => $parsedTimestamp,
                'created_at' => now(),
            ];

            if (DB::connection('central')->getSchemaBuilder()->hasColumn('router_telemetry', 'wan_interfaces')) {
                $telemetryData['wan_interfaces'] = $request->input('wan_interfaces');
            }

            DB::connection('central')->table('router_telemetry')->insert($telemetryData);

            Log::info('Telemetry stored successfully', [
                'site' => $site->name,
                'router_identity' => $telemetryData['router_identity'],
                'cpu_load' => $telemetryData['cpu_load'],
            ]);

            if (Cache::add("router_snapshot_sync_site_{$site->id}", true, now()->addSeconds(60))) {
                app(RouterSnapshotService::class)->syncForTelemetrySite($site);
            }

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

    private function getPreviousTelemetrySamples($routers): array
    {
        $previousSamples = [];

        foreach ($routers as $router) {
            $previous = DB::connection('central')->table('router_telemetry')
                ->where('id', '<', $router->id)
                ->where('router_identity', $router->router_identity)
                ->when($router->site_id === null, fn ($query) => $query->whereNull('site_id'), fn ($query) => $query->where('site_id', $router->site_id))
                ->orderBy('id', 'desc')
                ->first();

            if ($previous) {
                $previousSamples[$router->id] = $previous;
            }
        }

        return $previousSamples;
    }

    private function resourceTrend(Request $request): array
    {
        if (!DB::connection('central')->getSchemaBuilder()->hasTable('router_telemetry')) {
            return [];
        }

        $query = DB::connection('central')->table('router_telemetry')
            ->select([
                'cpu_load',
                'memory_used_mb',
                'memory_total_mb',
                'bandwidth_download_kbps',
                'bandwidth_upload_kbps',
                'total_rx_bytes',
                'total_tx_bytes',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->limit(12);

        $this->applyTelemetryScope($query, $request);

        return $query->get()
            ->reverse()
            ->map(function ($row) {
                $memory = (float) ($row->memory_total_mb ?? 0) > 0
                    ? ((float) ($row->memory_used_mb ?? 0) / (float) $row->memory_total_mb) * 100
                    : 0;

                return [
                    'cpu' => round((float) ($row->cpu_load ?? 0), 2),
                    'memory' => round($memory, 2),
                    'download' => round((float) ($row->bandwidth_download_kbps ?? 0), 2),
                    'upload' => round((float) ($row->bandwidth_upload_kbps ?? 0), 2),
                    'total_rx_bytes' => (int) ($row->total_rx_bytes ?? 0),
                    'total_tx_bytes' => (int) ($row->total_tx_bytes ?? 0),
                    'created_at' => $row->created_at,
                ];
            })
            ->values()
            ->all();
    }

    private function uptimeSecondsFromRequest(Request $request): int
    {
        $explicit = (int) $request->input('uptime_seconds', 0);
        if ($explicit > 0) {
            return $explicit;
        }

        return $this->parseRouterOsDuration((string) $request->input('uptime', ''));
    }

    private function parseRouterOsDuration(string $duration): int
    {
        $duration = trim($duration);
        if ($duration === '') {
            return 0;
        }

        $seconds = 0;
        if (preg_match_all('/(\d+)(w|d|h|m|s)/i', $duration, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = (int) $match[1];
                $seconds += match (strtolower($match[2])) {
                    'w' => $value * 604800,
                    'd' => $value * 86400,
                    'h' => $value * 3600,
                    'm' => $value * 60,
                    default => $value,
                };
            }
        }

        if ($seconds > 0) {
            return $seconds;
        }

        if (preg_match('/^(\d+):(\d+):(\d+)$/', $duration, $match)) {
            return ((int) $match[1] * 3600) + ((int) $match[2] * 60) + (int) $match[3];
        }

        return 0;
    }

    private function applyTelemetryScope($query, Request $request): void
    {
        $user = $request->user();
        $hasTenantIdColumn = DB::connection('central')
            ->getSchemaBuilder()
            ->hasColumn('router_telemetry', 'tenant_id');

        if ($hasTenantIdColumn && $user && isset($user->tenant_id) && $user->tenant_id) {
            $query->where('tenant_id', $user->tenant_id);
        }

        if ($request->header('X-Site-ID') && is_numeric($request->header('X-Site-ID'))) {
            $query->where('site_id', (int) $request->header('X-Site-ID'));
        }
    }

    private function usageWindow(string $period): array
    {
        $period = in_array($period, ['today', 'week', 'month'], true) ? $period : 'today';
        $now = now();

        $start = match ($period) {
            'week' => $now->copy()->startOfWeek(),
            'month' => $now->copy()->startOfMonth(),
            default => $now->copy()->startOfDay(),
        };

        return [$period, $start, $now];
    }

    private function emptyUsageResponse(string $period): array
    {
        [$period, $start, $end] = $this->usageWindow($period);

        return [
            'period' => $period,
            'start' => $start->toIso8601String(),
            'end' => $end->toIso8601String(),
            'download_bytes' => 0,
            'upload_bytes' => 0,
            'total_bytes' => 0,
            'sample_count' => 0,
            'wan_interfaces' => [],
        ];
    }

    private function calculateBandwidthRates($current, $previous): array
    {
        if (!$previous || !$current->created_at || !$previous->created_at) {
            return [
                'download' => round((float) ($current->bandwidth_download_kbps ?? 0), 2),
                'upload' => round((float) ($current->bandwidth_upload_kbps ?? 0), 2),
            ];
        }

        $seconds = max(1, \Carbon\Carbon::parse($previous->created_at)->diffInSeconds(\Carbon\Carbon::parse($current->created_at)));
        $rxDelta = max(0, (int) ($current->total_rx_bytes ?? 0) - (int) ($previous->total_rx_bytes ?? 0));
        $txDelta = max(0, (int) ($current->total_tx_bytes ?? 0) - (int) ($previous->total_tx_bytes ?? 0));

        return [
            'download' => round(($rxDelta * 8) / ($seconds * 1024), 2),
            'upload' => round(($txDelta * 8) / ($seconds * 1024), 2),
        ];
    }

    private function isReasonableWanDelta(int $rxDelta, int $txDelta, int $seconds): bool
    {
        if ($seconds <= 0) {
            return false;
        }

        // 10 Gbps ceiling for a MikroTik hotspot deployment. This filters bad counter jumps
        // caused by stale duplicate samples, interface changes, or corrupted telemetry posts.
        $maxBytes = (int) ceil((10_000_000_000 / 8) * $seconds);

        return $rxDelta <= $maxBytes && $txDelta <= $maxBytes;
    }
}
