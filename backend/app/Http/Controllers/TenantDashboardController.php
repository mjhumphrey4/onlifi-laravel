<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\MikrotikService;
use Illuminate\Http\Request;

class TenantDashboardController extends Controller
{
    private $mikrotikService;

    public function __construct(MikrotikService $mikrotikService)
    {
        $this->mikrotikService = $mikrotikService;
    }

    public function getRealtimeStats()
    {
        $routers = MikrotikRouter::where('is_active', true)->get();
        
        $totalActiveUsers = 0;
        $routerStats = [];

        foreach ($routers as $router) {
            $activeUsers = $router->last_active_connections ?? 0;
            $totalActiveUsers += $activeUsers;

            $routerStats[] = [
                'id' => $router->id,
                'name' => $router->name,
                'location' => $router->location,
                'cpu_load' => $router->last_cpu_load ?? 0,
                'memory_used_mb' => $router->last_memory_used_mb ?? 0,
                'memory_total_mb' => $router->memory_total_mb ?? 0,
                'active_users' => $activeUsers,
                'last_seen' => $router->last_seen,
                'is_online' => $router->last_seen && $router->last_seen->diffInMinutes(now()) < 10,
            ];
        }

        $todayTransactions = Transaction::whereDate('created_at', today())
            ->where('status', 'success')
            ->count();

        $todayRevenue = Transaction::whereDate('created_at', today())
            ->where('status', 'success')
            ->sum('amount');

        $activeVouchers = Voucher::where('status', 'active')->count();
        $unusedVouchers = Voucher::where('status', 'unused')->count();

        return response()->json([
            'total_active_users' => $totalActiveUsers,
            'total_routers' => $routers->count(),
            'online_routers' => collect($routerStats)->where('is_online', true)->count(),
            'today_transactions' => $todayTransactions,
            'today_revenue' => $todayRevenue,
            'active_vouchers' => $activeVouchers,
            'unused_vouchers' => $unusedVouchers,
            'routers' => $routerStats,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function getActiveUsers()
    {
        $routers = MikrotikRouter::where('is_active', true)->get();
        $allUsers = [];

        foreach ($routers as $router) {
            if ($this->mikrotikService->connect($router)) {
                try {
                    $users = $this->mikrotikService->getActiveUsers($router);
                    
                    foreach ($users as $user) {
                        $allUsers[] = [
                            'username' => $user['username'] ?? 'Unknown',
                            'mac_address' => $user['mac_address'] ?? 'Unknown',
                            'ip_address' => $user['ip_address'] ?? 'Unknown',
                            'uptime' => $user['uptime'] ?? '0s',
                            'bytes_in' => $user['bytes_in'] ?? 0,
                            'bytes_out' => $user['bytes_out'] ?? 0,
                            'router_id' => $router->id,
                            'router_name' => $router->name,
                            'router_location' => $router->location,
                        ];
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to get active users from router: ' . $router->name, [
                        'error' => $e->getMessage(),
                    ]);
                } finally {
                    $this->mikrotikService->disconnect();
                }
            }
        }

        return response()->json([
            'total_active_users' => count($allUsers),
            'users' => $allUsers,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    public function getRouterScript(Request $request)
    {
        $tenant = $request->user()->tenant ?? null;
        
        if (!$tenant) {
            return response()->json([
                'error' => 'Tenant not found',
            ], 404);
        }

        $router = MikrotikRouter::where('is_active', true)->first();

        if (!$router) {
            return response()->json([
                'error' => 'No router configured',
                'message' => 'Please add a router first before downloading the script',
            ], 404);
        }

        $apiUrl = config('app.url') . '/api/routers/telemetry/ingest';
        $apiKey = $tenant->api_key;
        $apiSecret = $tenant->api_secret;
        $routerId = $router->id;

        $script = $this->generateRouterScript($apiUrl, $apiKey, $apiSecret, $routerId);

        return response($script)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="onlifi-telemetry-' . $tenant->slug . '.rsc"');
    }

    private function generateRouterScript($apiUrl, $apiKey, $apiSecret, $routerId): string
    {
        return <<<RSC
# ============================================
# OnLiFi Router Telemetry Script (RouterOS)
# ============================================
# Auto-generated for your tenant
# This script sends real-time telemetry to your dashboard

#---------- CONFIGURATION ----------
:local dashboardUrl "{$apiUrl}"
:local tenantApiKey "{$apiKey}"
:local tenantApiSecret "{$apiSecret}"
:local routerId "{$routerId}"
:local schedulerName "onlifi-telemetry-scheduler"

#---------- TELEMETRY COLLECTION ----------
:global getSystemStats do={
  :local stats {"cpu"=0; "memory_total"=0; "memory_free"=0; "uptime"="0s";}
  :do {
    :set (\$stats->"cpu") [/system resource get cpu-load]
    :set (\$stats->"memory_total") [/system resource get total-memory]
    :set (\$stats->"memory_free") [/system resource get free-memory]
    :set (\$stats->"uptime") [/system resource get uptime]
  } on-error={}
  :return \$stats
}

:global getHotspotStats do={
  :local activeUsers 0
  :do {
    :set activeUsers [/ip hotspot active print count-only]
  } on-error={}
  :return \$activeUsers
}

#---------- MAIN TELEMETRY JOB ----------
:do {
  :put "OnLiFi: Collecting telemetry..."
  
  :local sysStats [\$getSystemStats]
  :local hotspotUsers [\$getHotspotStats]
  
  :local cpuVal (\$sysStats->"cpu")
  :local memTotal (\$sysStats->"memory_total")
  :local memFree (\$sysStats->"memory_free")
  :local memUsed (\$memTotal - \$memFree)
  
  :if ([:typeof \$cpuVal] != "num") do={ :set cpuVal 0 }
  :if ([:typeof \$memTotal] != "num") do={ :set memTotal 0 }
  :if ([:typeof \$memUsed] != "num") do={ :set memUsed 0 }
  :if ([:typeof \$hotspotUsers] != "num") do={ :set hotspotUsers 0 }
  
  :local memUsedMb (\$memUsed / 1048576)
  :local memTotalMb (\$memTotal / 1048576)
  
  :local reportJson "{"
  :set reportJson (\$reportJson . "\\"router_id\\":" . \$routerId . ",")
  :set reportJson (\$reportJson . "\\"cpu_load\\":" . \$cpuVal . ",")
  :set reportJson (\$reportJson . "\\"memory_used_mb\\":" . \$memUsedMb . ",")
  :set reportJson (\$reportJson . "\\"memory_total_mb\\":" . \$memTotalMb . ",")
  :set reportJson (\$reportJson . "\\"active_connections\\":" . \$hotspotUsers . ",")
  :set reportJson (\$reportJson . "\\"total_clients\\":" . \$hotspotUsers)
  :set reportJson (\$reportJson . "}")
  
  :put ("OnLiFi: CPU=" . \$cpuVal . "% Memory=" . \$memUsedMb . "MB Users=" . \$hotspotUsers)
  
  :do {
    /tool fetch url=\$dashboardUrl mode=https http-method=post http-data=\$reportJson \\
      http-header-field="X-API-Key: \$tenantApiKey,X-API-Secret: \$tenantApiSecret,Content-Type: application/json" \\
      keep-result=no
    :log info "onlifi-telemetry: data sent successfully"
    :put "SUCCESS: Telemetry sent to dashboard"
  } on-error={
    :log warning "onlifi-telemetry: failed to send data"
    :put "FAILED: Could not send telemetry"
  }
} on-error={
  :log warning "onlifi-telemetry: collection failed"
}

#---------- SCHEDULER SETUP (runs every 30 seconds) ----------
:if ([:len [/system scheduler find name=\$schedulerName]] = 0) do={
  /system scheduler add name=\$schedulerName start-time=startup interval=30s on-event="/system script run onlifi-telemetry"
  :log info "onlifi-telemetry: scheduler created - runs every 30 seconds"
  :put "Scheduler created: runs every 30 seconds for real-time updates"
} else={
  :put "Scheduler already exists"
}
RSC;
    }
}
