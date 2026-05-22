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

        // Get the router - either specified by ID or the first active one
        $routerId = $request->input('router_id');
        $router = $routerId
            ? MikrotikRouter::where('id', $routerId)->where('is_active', true)->first()
            : MikrotikRouter::where('is_active', true)->first();

        if (!$router) {
            return response()->json([
                'error' => 'No router configured',
                'message' => 'Please add a router first before downloading the script',
            ], 404);
        }

        // Get or create site for this router
        $site = $this->getOrCreateSiteForRouter($router, $tenant);

        if (!$site || !$site->api_token) {
            return response()->json([
                'error' => 'Site not configured',
                'message' => 'Could not generate API token for telemetry',
            ], 500);
        }

        // Use the public telemetry endpoint with Bearer token auth
        $apiUrl = rtrim(config('app.url'), '/') . '/api/telemetry';
        $apiToken = $site->api_token;
        $routerIdentity = $router->name;

        $script = $this->generateRouterScript($apiUrl, $apiToken, $routerIdentity, $site->name);

        return response($script)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="onlifi-telemetry-' . $tenant->slug . '-' . $router->name . '.rsc"');
    }

    /**
     * Get or create a site for the router to get an API token
     */
    private function getOrCreateSiteForRouter($router, $tenant)
    {
        // If router already has a site_id, use that site
        if ($router->site_id) {
            return \App\Models\Site::find($router->site_id);
        }

        // Check if a site exists for this tenant with the router's name
        $site = \App\Models\Site::where('tenant_id', $tenant->id)
            ->where('name', $router->name)
            ->first();

        if (!$site) {
            // Create a new site for this router
            $site = \App\Models\Site::create([
                'tenant_id' => $tenant->id,
                'name' => $router->name,
                'slug' => \Illuminate\Support\Str::slug($router->name),
                'description' => 'Auto-generated site for ' . $router->name,
                'is_active' => true,
            ]);
        }

        // Link router to site
        $router->update(['site_id' => $site->id]);

        return $site;
    }

    private function generateRouterScript($apiUrl, $apiToken, $routerIdentity, $siteName): string
    {
        return <<<RSC
# ============================================
# OnLiFi Router Telemetry Script (RouterOS)
# ============================================
# Auto-generated for router: {$routerIdentity}
# Site: {$siteName}
# This script sends real-time telemetry to your dashboard
#
# INSTALLATION:
# 1. Copy this entire script
# 2. In MikroTik Terminal: /system script add name=onlifi-telemetry source="<paste script here>"
# 3. Run manually first: /system script run onlifi-telemetry
# 4. Script will auto-create scheduler to run every 5 minutes

#---------- CONFIGURATION ----------
:local dashboardUrl "{$apiUrl}"
:local apiToken "{$apiToken}"
:local routerIdentity "{$routerIdentity}"
:local schedulerName "onlifi-telemetry-scheduler"

#---------- TELEMETRY COLLECTION FUNCTIONS ----------

# Get system resources safely
:global getSystemStats do={
  :local stats {"cpu"=0; "memory_total"=0; "memory_free"=0; "uptime"="0s"; "version"=""; "board"=""}
  :do {
    :set (\$stats->"cpu") [/system resource get cpu-load]
    :set (\$stats->"memory_total") [/system resource get total-memory]
    :set (\$stats->"memory_free") [/system resource get free-memory]
    :set (\$stats->"uptime") [/system resource get uptime]
    :set (\$stats->"version") [/system resource get version]
    :set (\$stats->"board") [/system resource get board-name]
  } on-error={}
  :return \$stats
}

# Get interface statistics with TX/RX rates
:global getInterfaceStats do={
  :local totalTxBytes 0
  :local totalRxBytes 0
  :do {
    :foreach interface in=[/interface find] do={
      :local running false
      :do { :set running [/interface get \$interface running] } on-error={}
      :if (\$running = true) do={
        :local txBytes 0
        :local rxBytes 0
        :do {
          :set txBytes [/interface get \$interface tx-byte]
          :set rxBytes [/interface get \$interface rx-byte]
        } on-error={}
        :set totalTxBytes (\$totalTxBytes + \$txBytes)
        :set totalRxBytes (\$totalRxBytes + \$rxBytes)
      }
    }
  } on-error={}
  :return {"total_tx_bytes"=\$totalTxBytes; "total_rx_bytes"=\$totalRxBytes}
}

# Get hotspot active users
:global getHotspotStats do={
  :local activeUsers 0
  :do { :set activeUsers [/ip hotspot active print count-only] } on-error={}
  :return \$activeUsers
}

# Convert uptime to seconds
:global uptimeToSeconds do={
  :local uptime \$1
  :local seconds 0
  :do {
    :local str [:tostr \$uptime]
    :local weeks 0; :local days 0; :local hours 0; :local minutes 0; :local secs 0
    :if ([:find \$str "w"] >= 0) do={
      :set weeks [:pick \$str 0 [:find \$str "w"]]
      :set str [:pick \$str ([:find \$str "w"] + 1) [:len \$str]]
    }
    :if ([:find \$str "d"] >= 0) do={
      :set days [:pick \$str 0 [:find \$str "d"]]
      :set str [:pick \$str ([:find \$str "d"] + 1) [:len \$str]]
    }
    :local colonPos1 [:find \$str ":"]
    :if (\$colonPos1 >= 0) do={
      :set hours [:pick \$str 0 \$colonPos1]
      :local remaining [:pick \$str (\$colonPos1 + 1) [:len \$str]]
      :local colonPos2 [:find \$remaining ":"]
      :if (\$colonPos2 >= 0) do={
        :set minutes [:pick \$remaining 0 \$colonPos2]
        :set secs [:pick \$remaining (\$colonPos2 + 1) [:len \$remaining]]
      }
    }
    :set seconds ([:tonum \$weeks] * 604800 + [:tonum \$days] * 86400 + [:tonum \$hours] * 3600 + [:tonum \$minutes] * 60 + [:tonum \$secs])
  } on-error={}
  :return \$seconds
}

#---------- MAIN TELEMETRY JOB ----------
:do {
  :put "OnLiFi: Starting telemetry collection..."

  # Collect all telemetry data
  :local sysStats [\$getSystemStats]
  :local interfaceData [\$getInterfaceStats]
  :local hotspotUsers [\$getHotspotStats]

  # Get router info from system
  :local routerVersion (\$sysStats->"version")
  :local routerBoard (\$sysStats->"board")
  :if ([:typeof \$routerVersion] != "str") do={ :set routerVersion "" }
  :if ([:typeof \$routerBoard] != "str") do={ :set routerBoard "" }

  # Get timestamp
  :local currentTime [/system clock get time]
  :local currentDate [/system clock get date]
  :local timestamp (\$currentDate . " " . \$currentTime)

  # Extract values
  :local cpuVal (\$sysStats->"cpu")
  :local memTotal (\$sysStats->"memory_total")
  :local memFree (\$sysStats->"memory_free")
  :local memUsed (\$memTotal - \$memFree)
  :local rawUptime (\$sysStats->"uptime")
  :local uptimeSeconds [\$uptimeToSeconds \$rawUptime]
  :local totalTxBytes (\$interfaceData->"total_tx_bytes")
  :local totalRxBytes (\$interfaceData->"total_rx_bytes")

  # Validate numeric values
  :if ([:typeof \$cpuVal] != "num") do={ :set cpuVal 0 }
  :if ([:typeof \$memTotal] != "num") do={ :set memTotal 0 }
  :if ([:typeof \$memFree] != "num") do={ :set memFree 0 }
  :if ([:typeof \$memUsed] != "num") do={ :set memUsed 0 }
  :if ([:typeof \$uptimeSeconds] != "num") do={ :set uptimeSeconds 0 }
  :if ([:typeof \$hotspotUsers] != "num") do={ :set hotspotUsers 0 }
  :if ([:typeof \$totalTxBytes] != "num") do={ :set totalTxBytes 0 }
  :if ([:typeof \$totalRxBytes] != "num") do={ :set totalRxBytes 0 }

  # Convert memory to MB
  :local memUsedMb (\$memUsed / 1048576)
  :local memTotalMb (\$memTotal / 1048576)

  # Calculate bandwidth in Kbps (rough estimate based on 5-minute interval)
  :local bandwidthDownKbps 0
  :local bandwidthUpKbps 0
  :if (\$totalRxBytes > 0) do={ :set bandwidthDownKbps ((\$totalRxBytes * 8) / (300 * 1024)) }
  :if (\$totalTxBytes > 0) do={ :set bandwidthUpKbps ((\$totalTxBytes * 8) / (300 * 1024)) }

  # Build JSON payload
  :local reportJson "{"
  :set reportJson (\$reportJson . "\\"router_identity\\":\\"" . \$routerIdentity . "\\",")
  :set reportJson (\$reportJson . "\\"router_version\\":\\"" . \$routerVersion . "\\",")
  :set reportJson (\$reportJson . "\\"router_board\\":\\"" . \$routerBoard . "\\",")
  :set reportJson (\$reportJson . "\\"timestamp\\":\\"" . \$timestamp . "\\",")
  :set reportJson (\$reportJson . "\\"cpu_load\\":" . \$cpuVal . ",")
  :set reportJson (\$reportJson . "\\"memory_total_mb\\":" . \$memTotalMb . ",")
  :set reportJson (\$reportJson . "\\"memory_used_mb\\":" . \$memUsedMb . ",")
  :set reportJson (\$reportJson . "\\"uptime_seconds\\":" . \$uptimeSeconds . ",")
  :set reportJson (\$reportJson . "\\"active_connections\\":" . \$hotspotUsers . ",")
  :set reportJson (\$reportJson . "\\"bandwidth_download_kbps\\":" . \$bandwidthDownKbps . ",")
  :set reportJson (\$reportJson . "\\"bandwidth_upload_kbps\\":" . \$bandwidthUpKbps . ",")
  :set reportJson (\$reportJson . "\\"total_tx_bytes\\":" . \$totalTxBytes . ",")
  :set reportJson (\$reportJson . "\\"total_rx_bytes\\":" . \$totalRxBytes)
  :set reportJson (\$reportJson . "}")

  # Debug output
  :put ("OnLiFi: Router: " . \$routerIdentity)
  :put ("OnLiFi: CPU: " . \$cpuVal . "%")
  :put ("OnLiFi: Memory: " . \$memUsedMb . "/" . \$memTotalMb . " MB")
  :put ("OnLiFi: Users: " . \$hotspotUsers)

  # POST telemetry to API with Bearer token
  :do {
    /tool fetch url=\$dashboardUrl mode=http http-method=post http-data=\$reportJson http-header-field="Authorization: Bearer \$apiToken,Content-Type: application/json" keep-result=no
    :log info "OnLiFi telemetry: Data posted successfully"
    :put "SUCCESS: Telemetry posted to dashboard"
  } on-error={
    :log warning "OnLiFi telemetry: Failed to post data"
    :put "FAILED: Could not post telemetry - check network/API token"
  }

} on-error={
  :log warning "OnLiFi telemetry: Collection failed"
  :put "FAILED: Telemetry collection aborted"
}

#---------- SCHEDULER SETUP (runs every 5 minutes) ----------
:if ([:len [/system scheduler find name=\$schedulerName]] = 0) do={
  /system scheduler add name=\$schedulerName start-time=startup interval=5m on-event="/system script run onlifi-telemetry"
  :log info "OnLiFi telemetry: Scheduler created - runs every 5 minutes"
  :put "Scheduler created: runs every 5 minutes"
} else={
  :put "Scheduler already exists"
}
RSC;
    }
}
