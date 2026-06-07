<?php

namespace App\Http\Controllers;

use App\Models\MikrotikRouter;
use App\Models\SystemSetting;
use App\Models\Transaction;
use App\Models\Voucher;
use App\Services\MikrotikService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantDashboardController extends Controller
{
    private $mikrotikService;

    public function __construct(MikrotikService $mikrotikService)
    {
        $this->mikrotikService = $mikrotikService;
    }

    public function getRealtimeStats(Request $request)
    {
        $site = SiteScope::selectedSite($request);
        $tenantId = app()->bound('tenant') ? app('tenant')->id : 'unknown';
        $cacheKey = "tenant:{$tenantId}:site:" . ($site?->id ?: 'default') . ':dashboard:realtime-stats:v2';

        if (!$request->boolean('refresh') && ($cached = Cache::get($cacheKey))) {
            $cached['cache'] = [
                'source' => 'redis',
                'ttl_seconds' => 300,
            ];

            return response()->json($cached);
        }

        $routerQuery = MikrotikRouter::where('is_active', true);
        if ($site && Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
            $routerQuery->where('site_id', $site->id);
        }
        $routers = $routerQuery->get();
        
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

        $transactionQuery = Transaction::query();
        SiteScope::applyToTenantTable($transactionQuery, 'transactions', $site, 'origin_site');
        $totalSuccessfulTransactions = (clone $transactionQuery)->where('status', 'success')->count();
        $totalRevenue = (clone $transactionQuery)->where('status', 'success')->sum('amount');
        $todayTransactions = (clone $transactionQuery)->whereDate('created_at', today())->where('status', 'success')->count();
        $todayRevenue = (clone $transactionQuery)->whereDate('created_at', today())->where('status', 'success')->sum('amount');

        $voucherQuery = Voucher::query();
        SiteScope::applyToTenantTable($voucherQuery, 'vouchers', $site);
        $this->hideManualPaymentVouchers($voucherQuery);
        $activeVouchers = (clone $voucherQuery)->whereIn('status', ['reserved', 'in_use'])->count();
        $unusedVouchers = (clone $voucherQuery)->where('status', 'unused')->count();
        $vouchersSold = (clone $voucherQuery)->whereNotNull('first_used_at')->count();
        $voucherRevenue = (clone $voucherQuery)->whereNotNull('first_used_at')->sum('price');

        $payload = [
            'total_active_users' => $totalActiveUsers,
            'total_routers' => $routers->count(),
            'online_routers' => collect($routerStats)->where('is_online', true)->count(),
            'total_successful_transactions' => $totalSuccessfulTransactions,
            'total_revenue' => $totalRevenue,
            'today_transactions' => $todayTransactions,
            'today_revenue' => $todayRevenue,
            'active_vouchers' => $activeVouchers,
            'unused_vouchers' => $unusedVouchers,
            'vouchers_sold' => $vouchersSold,
            'voucher_revenue' => $voucherRevenue,
            'routers' => $routerStats,
            'timestamp' => now()->toIso8601String(),
            'cache' => [
                'source' => 'database',
                'ttl_seconds' => 300,
            ],
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
    }

    private function hideManualPaymentVouchers($query)
    {
        $hasCreatedBy = Schema::connection('tenant')->hasColumn('voucher_groups', 'created_by');
        $hasDescription = Schema::connection('tenant')->hasColumn('voucher_groups', 'description');

        if (!Schema::connection('tenant')->hasTable('voucher_groups') || (!$hasCreatedBy && !$hasDescription)) {
            return $query;
        }

        return $query->whereDoesntHave('group', function ($groupQuery) use ($hasCreatedBy, $hasDescription) {
            if ($hasCreatedBy) {
                $groupQuery->where('created_by', 'manual-payment');
            }

            if ($hasDescription) {
                $groupQuery->orWhere('description', 'like', '%Auto-created by manual payment%');
            }
        });
    }

    public function getActiveUsers(Request $request)
    {
        $site = SiteScope::selectedOrDefaultSite($request);
        $tenantId = app()->bound('tenant') ? app('tenant')->id : 'unknown';
        $cacheKey = "tenant:{$tenantId}:site:" . ($site?->id ?: 'default') . ':dashboard:active-users';

        if (!$request->boolean('refresh') && ($cached = Cache::get($cacheKey))) {
            $cached['cache'] = [
                'source' => 'redis',
                'ttl_seconds' => 300,
            ];

            return response()->json($cached);
        }

        if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
            return response()->json([
                'total_active_users' => 0,
                'users' => [],
                'cached' => true,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        $query = DB::connection('tenant')
            ->table('hotspot_users')
            ->where('last_seen', '>=', now()->subMinutes(6));

        if ($site && Schema::connection('tenant')->hasColumn('hotspot_users', 'site_id')) {
            $query->where('site_id', $site->id);
        } elseif ($site && Schema::connection('tenant')->hasColumn('hotspot_users', 'router_name')) {
            $query->where('router_name', $site->name);
        }

        $users = $query->orderByDesc('last_seen')
            ->limit(100)
            ->get()
            ->map(fn ($user) => [
                'username' => $user->username ?? '',
                'hostname' => $user->hostname ?? '',
                'mac_address' => $user->mac_address ?? '',
                'ip_address' => $user->ip_address ?? '',
                'uptime' => $user->uptime_seconds ?? 0,
                'bytes_in' => (float) ($user->data_uploaded_mb ?? 0) * 1048576,
                'bytes_out' => (float) ($user->data_downloaded_mb ?? 0) * 1048576,
                'router_name' => $user->router_name ?? '',
                'router_location' => $user->router_identity ?? '',
                'last_seen' => $user->last_seen,
            ]);

        $payload = [
            'total_active_users' => $users->count(),
            'users' => $users,
            'cached' => true,
            'timestamp' => now()->toIso8601String(),
            'cache' => [
                'source' => 'database',
                'ttl_seconds' => 300,
            ],
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(5));

        return response()->json($payload);
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
        $apiBaseUrl = rtrim((string) SystemSetting::get('api_base_url', config('app.api_url', config('app.url'))), '/');
        $apiUrl = $apiBaseUrl . '/api/telemetry';
        $apiToken = $site->api_token;
        $routerIdentity = $router->name;

        $script = $this->generateRouterScript($apiUrl, str_starts_with($apiUrl, 'https://') ? 'https' : 'http', $apiToken, $routerIdentity, $site->name);

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

    private function generateRouterScript($apiUrl, $fetchMode, $apiToken, $routerIdentity, $siteName): string
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
# 4. Script will auto-create scheduler to run every 30 seconds

#---------- CONFIGURATION ----------
:local dashboardUrl "{$apiUrl}"
:local fetchMode "{$fetchMode}"
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
  :local wanInterfaces ""
  :local wanCount 0
  :do {
    :foreach member in=[/interface list member find list="WAN"] do={
      :local ifaceName [/interface list member get \$member interface]
      :local ifaceId [/interface find name=\$ifaceName]
      :if ([:len \$ifaceId] > 0) do={
        :set totalTxBytes (\$totalTxBytes + [/interface get \$ifaceId tx-byte])
        :set totalRxBytes (\$totalRxBytes + [/interface get \$ifaceId rx-byte])
        :if ([:len \$wanInterfaces] = 0) do={ :set wanInterfaces \$ifaceName } else={ :set wanInterfaces (\$wanInterfaces . "," . \$ifaceName) }
        :set wanCount (\$wanCount + 1)
      }
    }
  } on-error={}
  :if (\$wanCount = 0) do={
    :do {
      :local fallbackWan [/interface find name="ether1"]
      :if ([:len \$fallbackWan] > 0) do={
        :set totalTxBytes [/interface get \$fallbackWan tx-byte]
        :set totalRxBytes [/interface get \$fallbackWan rx-byte]
        :set wanInterfaces "ether1"
      }
    } on-error={}
  }
  :return {"total_tx_bytes"=\$totalTxBytes; "total_rx_bytes"=\$totalRxBytes; "wan_interfaces"=\$wanInterfaces}
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
  :local wanInterfaces (\$interfaceData->"wan_interfaces")

  # Validate numeric values
  :if ([:typeof \$cpuVal] != "num") do={ :set cpuVal 0 }
  :if ([:typeof \$memTotal] != "num") do={ :set memTotal 0 }
  :if ([:typeof \$memFree] != "num") do={ :set memFree 0 }
  :if ([:typeof \$memUsed] != "num") do={ :set memUsed 0 }
  :if ([:typeof \$uptimeSeconds] != "num") do={ :set uptimeSeconds 0 }
  :if ([:typeof \$hotspotUsers] != "num") do={ :set hotspotUsers 0 }
  :if ([:typeof \$totalTxBytes] != "num") do={ :set totalTxBytes 0 }
  :if ([:typeof \$totalRxBytes] != "num") do={ :set totalRxBytes 0 }
  :if ([:typeof \$wanInterfaces] != "str") do={ :set wanInterfaces "" }

  # Convert memory to MB
  :local memUsedMb (\$memUsed / 1048576)
  :local memTotalMb (\$memTotal / 1048576)

  # Dashboard calculates bandwidth rate from byte-counter deltas between samples
  :local bandwidthDownKbps 0
  :local bandwidthUpKbps 0

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
  :set reportJson (\$reportJson . "\\"total_rx_bytes\\":" . \$totalRxBytes . ",")
  :set reportJson (\$reportJson . "\\"wan_interfaces\\":\\"" . \$wanInterfaces . "\\"")
  :set reportJson (\$reportJson . "}")

  # Debug output
  :put ("OnLiFi: Router: " . \$routerIdentity)
  :put ("OnLiFi: CPU: " . \$cpuVal . "%")
  :put ("OnLiFi: Memory: " . \$memUsedMb . "/" . \$memTotalMb . " MB")
  :put ("OnLiFi: Users: " . \$hotspotUsers)

  # POST telemetry to API with Bearer token
  :do {
    /tool fetch url=\$dashboardUrl mode=\$fetchMode http-method=post http-data=\$reportJson http-header-field="Authorization: Bearer \$apiToken,Content-Type: application/json" keep-result=no
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

#---------- SCHEDULER SETUP (runs every 30 seconds) ----------
:if ([:len [/system scheduler find name=\$schedulerName]] = 0) do={
  /system scheduler add name=\$schedulerName start-time=startup interval=30s on-event="/system script run onlifi-telemetry"
  :log info "OnLiFi telemetry: Scheduler created - runs every 30 seconds"
  :put "Scheduler created: runs every 30 seconds"
} else={
  :put "Scheduler already exists"
}
RSC;
    }
}
