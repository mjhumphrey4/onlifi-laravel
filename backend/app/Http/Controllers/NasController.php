<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Models\Site;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * NasController - Manages NAS (Network Access Server) entries for FreeRADIUS
 * 
 * Each MikroTik router needs a unique NAS entry that maps it to a tenant.
 * Since routers don't have public IPs, we use a unique router_identifier
 * (sent via NAS-Identifier attribute) to identify which tenant the router belongs to.
 */
class NasController extends Controller
{
    /**
     * List all NAS entries for the current tenant
     */
    public function index(Request $request)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $site = SiteScope::selectedSite($request);
        if ($site) {
            $this->ensureNasForSite($tenant, $site);
        }

        $nasEntries = DB::connection('central')->table('nas')
            ->where('tenant_id', $tenant->id)
            ->when($site && Schema::connection('central')->hasColumn('nas', 'site_id'), fn ($query) => $query->where('site_id', $site->id))
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'nas_entries' => $nasEntries,
            'radius_server' => $this->radiusServerIp(),
            'radius_port' => $this->radiusAuthPort(),
            'radius_acct_port' => $this->radiusAcctPort(),
        ]);
    }
    
    /**
     * Register a new router as a NAS
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:200',
            'router_id' => 'nullable|integer',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $site = SiteScope::selectedSite($request);
        if (!$site) {
            return response()->json(['error' => 'Select a site before provisioning its router'], 422);
        }

        $nas = $this->ensureNasForSite($tenant, $site, $request->input('description'));
        $mikrotikScript = $this->generateFullProvisioningScript($nas);
        
        return response()->json([
            'message' => 'Site router provisioning endpoint is ready',
            'nas_id' => $nas->id,
            'nas' => $nas,
            'router_identifier' => $nas->router_identifier,
            'provisioning_url' => $this->provisioningUrl($nas->provisioning_token),
            'fetch_command' => $this->fetchCommand($nas->provisioning_token),
            'radius_config' => [
                'server' => $this->radiusServerIp(),
                'auth_port' => $this->radiusAuthPort(),
                'acct_port' => $this->radiusAcctPort(),
                'secret' => $nas->secret,
                'nas_identifier' => $nas->router_identifier,
            ],
            'mikrotik_script' => $mikrotikScript,
        ], 200);
    }
    
    /**
     * Get details of a specific NAS entry
     */
    public function show(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nas = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$nas) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        $nas = $this->ensureProvisioningToken($nas);
        $mikrotikScript = $this->generateFullProvisioningScript($nas);
        
        return response()->json([
            'nas' => $nas,
            'mikrotik_script' => $mikrotikScript,
            'provisioning_url' => $this->provisioningUrl($nas->provisioning_token),
            'fetch_command' => $this->fetchCommand($nas->provisioning_token),
        ]);
    }
    
    /**
     * Update a NAS entry
     */
    public function update(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nas = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$nas) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:200',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        DB::connection('central')->table('nas')
            ->where('id', $id)
            ->update([
                'shortname' => $request->input('name', $nas->shortname),
                'description' => $request->input('description', $nas->description),
                'updated_at' => now(),
            ]);
        
        return response()->json([
            'message' => 'NAS entry updated successfully',
        ]);
    }
    
    /**
     * Delete a NAS entry
     */
    public function destroy(Request $request, $id)
    {
        return response()->json([
            'error' => 'Site routers are managed automatically',
            'message' => 'Delete or deactivate the site instead of deleting its router record.',
        ], 400);
    }

    private function ensureNasForSite($tenant, Site $site, ?string $description = null)
    {
        $query = DB::connection('central')->table('nas')
            ->where('tenant_id', $tenant->id);

        if (Schema::connection('central')->hasColumn('nas', 'site_id')) {
            $query->where('site_id', $site->id);
        } else {
            $query->where('shortname', $site->name);
        }

        $nas = $query->orderBy('id')->first();
        if ($nas) {
            $updates = [];
            if ($nas->shortname !== $site->name) {
                $updates['shortname'] = $site->name;
            }
            if (Schema::connection('central')->hasColumn('nas', 'site_id') && empty($nas->site_id)) {
                $updates['site_id'] = $site->id;
            }
            if (empty($nas->provisioning_token)) {
                $updates['provisioning_token'] = Str::random(64);
            }
            $routerIdentifier = $this->generateRouterIdentifier($tenant, $site, $nas->id);
            if ($nas->router_identifier !== $routerIdentifier) {
                $updates['router_identifier'] = $routerIdentifier;
            }
            if ($description !== null) {
                $updates['description'] = $description;
            }

            if ($updates) {
                $updates['updated_at'] = now();
                DB::connection('central')->table('nas')->where('id', $nas->id)->update($updates);
                $nas = DB::connection('central')->table('nas')->where('id', $nas->id)->first();
            }

            return $this->ensureProvisioningToken($nas);
        }

        $nasId = DB::connection('central')->table('nas')->insertGetId([
            'nasname' => '0.0.0.0/0',
            'router_identifier' => $this->generateRouterIdentifier($tenant, $site),
            'provisioning_token' => Str::random(64),
            'shortname' => $site->name,
            'type' => 'other',
            'secret' => $tenant->radius_secret ?? config('radius.shared_secret', 'onlifi_radius_secret'),
            'server' => null,
            'description' => $description ?: $site->description,
            'tenant_id' => $tenant->id,
            ...(Schema::connection('central')->hasColumn('nas', 'site_id') ? ['site_id' => $site->id] : []),
            'router_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::connection('central')->table('nas')->where('id', $nasId)->first();
    }

    /**
     * Regenerate router identifier for a NAS
     */
    public function regenerateIdentifier(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nas = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$nas) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        $site = null;
        if (Schema::connection('central')->hasColumn('nas', 'site_id') && !empty($nas->site_id)) {
            $site = Site::where('tenant_id', $tenant->id)->where('id', $nas->site_id)->first();
        }
        $newIdentifier = $this->generateRouterIdentifier($tenant, $site, $nas->id);
        
        DB::connection('central')->table('nas')
            ->where('id', $id)
            ->update([
                'router_identifier' => $newIdentifier,
                'updated_at' => now(),
            ]);
        
        $nas = DB::connection('central')->table('nas')->where('id', $id)->first();
        $nas = $this->ensureProvisioningToken($nas);
        $mikrotikScript = $this->generateFullProvisioningScript($nas);
        
        return response()->json([
            'message' => 'Router identifier regenerated',
            'router_identifier' => $newIdentifier,
            'mikrotik_script' => $mikrotikScript,
        ]);
    }
    
    /**
     * Get MikroTik RADIUS configuration script
     */
    public function getMikrotikScript(Request $request, $id)
    {
        $tenant = $this->getTenant($request);
        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }
        
        $nas = DB::connection('central')->table('nas')
            ->where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if (!$nas) {
            return response()->json(['error' => 'NAS entry not found'], 404);
        }
        
        $nas = $this->ensureProvisioningToken($nas);
        $script = $this->generateFullProvisioningScript($nas);
        
        return response($script)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', "attachment; filename=\"radius-config-{$nas->shortname}.rsc\"");
    }

    public function publicProvisioningScript(string $token)
    {
        $nas = DB::connection('central')->table('nas')
            ->where('provisioning_token', $token)
            ->first();

        if (!$nas) {
            return response('# Invalid or expired OnLiFi provisioning token', 404)
                ->header('Content-Type', 'text/plain');
        }

        return response($this->generateFullProvisioningScript($nas))
            ->header('Content-Type', 'text/plain');
    }
    
    /**
     * Generate readable router identifier.
     * Format: {site-slug}-ONLIFI-1, with a numeric fallback if another tenant already uses it.
     */
    private function generateRouterIdentifier($tenant, ?Site $site = null, ?int $ignoreNasId = null): string
    {
        $sitePart = Str::slug($site?->name ?: $tenant->slug ?: $tenant->name ?: 'site', '-');
        $base = "{$sitePart}-ONLIFI";
        $sequence = 1;

        do {
            $identifier = "{$base}-{$sequence}";
            $exists = DB::connection('central')
                ->table('nas')
                ->where('router_identifier', $identifier)
                ->when($ignoreNasId, fn ($query) => $query->where('id', '!=', $ignoreNasId))
                ->exists();
            $sequence++;
        } while ($exists);

        return $identifier;
    }

    private function ensureProvisioningToken($nas)
    {
        if ($nas && empty($nas->provisioning_token)) {
            $token = Str::random(64);
            DB::connection('central')->table('nas')
                ->where('id', $nas->id)
                ->update(['provisioning_token' => $token, 'updated_at' => now()]);
            $nas->provisioning_token = $token;
        }

        return $nas;
    }

    private function provisioningUrl(string $token): string
    {
        return $this->apiBaseUrl() . "/api/router/provision/{$token}";
    }

    private function fetchCommand(string $token): string
    {
        $url = $this->provisioningUrl($token);
        return "/tool fetch url=\"{$url}\" mode={$this->fetchModeForUrl($url)} dst-path=onlifi-setup.rsc; /import file-name=onlifi-setup.rsc";
    }

    private function apiBaseUrl(): string
    {
        return rtrim((string) SystemSetting::get('api_base_url', config('app.api_url', config('app.url'))), '/');
    }

    private function manualPaymentBaseUrl(): string
    {
        return rtrim((string) SystemSetting::get('manual_payment_base_url', config('app.manual_payment_base_url')), '/');
    }

    private function fetchModeForUrl(string $url): string
    {
        return str_starts_with(strtolower($url), 'https://') ? 'https' : 'http';
    }

    private function radiusServerIp(): string
    {
        return (string) SystemSetting::get('radius_server_ip', config('radius.server_ip', '129.168.0.42'));
    }

    private function radiusAuthPort(): int
    {
        return (int) SystemSetting::get('radius_auth_port', config('radius.auth_port', 1812));
    }

    private function radiusAcctPort(): int
    {
        return (int) SystemSetting::get('radius_acct_port', config('radius.acct_port', 1813));
    }
    
    /**
     * Generate MikroTik RADIUS configuration script
     */
    private function generateFullProvisioningScript($nas): string
    {
        $routerIdentifier = $nas->router_identifier;
        $secret = $nas->secret;
        $tenant = DB::connection('central')->table('tenants')->where('id', $nas->tenant_id)->first();
        $tenantName = $tenant->name ?? 'Unknown Tenant';
        $site = $this->getOrCreateProvisioningSite($nas, $tenant);
        $apiBaseUrl = $this->apiBaseUrl();
        $telemetryUrl = $apiBaseUrl . '/api/telemetry';
        $telemetryToken = $site?->api_token ?? '';
        $serverIp = $this->radiusServerIp();
        $authPort = $this->radiusAuthPort();
        $acctPort = $this->radiusAcctPort();
        $lanAddress = (string) SystemSetting::get('router_default_lan_cidr', '10.10.0.1/24');
        $lanGateway = explode('/', $lanAddress)[0];
        $dhcpNetwork = $this->dhcpNetworkFromCidr($lanAddress);
        $poolRange = (string) SystemSetting::get('router_default_dhcp_pool', '10.10.0.10-10.10.0.254');
        $dnsServers = (string) SystemSetting::get('router_default_dns_servers', '1.1.1.1,8.8.8.8');
        $hotspotDns = (string) SystemSetting::get('router_default_hotspot_dns', 'wifi.onlifi.local');
        $remoteAdminUser = (string) SystemSetting::get('router_admin_username', 'onlifi');
        $remoteAdminPassword = (string) SystemSetting::get('router_admin_password', 'onlifi-router-admin-change-me');
        $remoteVpnCidr = (string) SystemSetting::get('router_remote_vpn_cidr', '10.10.1.0/24');
        $vpnHost = $site?->vpn_public_host ?: 'vpn.onlifi.net';
        $vpnPort = $site?->vpn_public_port ?: 443;
        $vpnUsername = $site?->vpn_username ?: Str::slug($site?->name ?: $tenantName);
        $vpnPassword = $site?->vpn_password ?: Str::random(24);
        $appHost = parse_url($apiBaseUrl, PHP_URL_HOST) ?: $serverIp;
        $paymentHost = parse_url($this->manualPaymentBaseUrl(), PHP_URL_HOST) ?: 'pay.onlifi.net';
        $hotspotBaseUrl = $apiBaseUrl . "/api/captive/hotspot/{$nas->provisioning_token}";
        $portalConfigUrl = $apiBaseUrl . "/api/captive/config/{$nas->provisioning_token}";
        $hotspotFetchMode = $this->fetchModeForUrl($hotspotBaseUrl);
        $telemetryFetchMode = $this->fetchModeForUrl($telemetryUrl);

        return <<<RSC
# ============================================
# OnLiFi Full Router Provisioning Script
# ============================================
# Router Identifier: {$routerIdentifier}
# Tenant: {$tenantName}
# Generated: {now()->toIso8601String()}
#
# This script is idempotent and safe to run more than once.
# Defaults:
# - WAN: ether1
# - LAN bridge: onlifi-lan
# - Hotspot network: {$lanAddress}
# ============================================

:local wanInterface "ether1"
:local bridgeName "onlifi-lan"
:local hotspotName "onlifi-hotspot"
:local hotspotProfile "onlifi-hotspot-profile"
:local userProfile "onlifi-voucher-profile"
:local dhcpPool "onlifi-dhcp-pool"
:local dhcpServer "onlifi-dhcp"
:local lanAddress "{$lanAddress}"
:local lanGateway "{$lanGateway}"
:local poolRange "{$poolRange}"
:local dnsServers "{$dnsServers}"
:local hotspotDnsName "{$hotspotDns}"
:local radiusAddress "{$serverIp}"
:local radiusSecret "{$secret}"
:local radiusAuthPort "{$authPort}"
:local radiusAcctPort "{$acctPort}"
:local routerIdentifier "{$routerIdentifier}"
:local remoteAdminUser "{$remoteAdminUser}"
:local remoteAdminPassword "{$remoteAdminPassword}"
:local remoteVpnCidr "{$remoteVpnCidr}"
:local sstpHost "{$vpnHost}"
:local sstpPort "{$vpnPort}"
:local sstpUser "{$vpnUsername}"
:local sstpPassword "{$vpnPassword}"
:local sstpClientName "onlifi-sstp"
:local telemetryUrl "{$telemetryUrl}"
:local telemetryToken "{$telemetryToken}"
:local appHost "{$appHost}"
:local paymentHost "{$paymentHost}"
:local hotspotBaseUrl "{$hotspotBaseUrl}"
:local portalConfigUrl "{$portalConfigUrl}"
:local hotspotFetchMode "{$hotspotFetchMode}"
:local telemetryFetchMode "{$telemetryFetchMode}"
:local telemetryScriptName "onlifi-telemetry"
:local telemetrySchedulerName "onlifi-telemetry-scheduler"

:put "OnLiFi: Starting full router provisioning..."

# Identity
/system identity set name=\$routerIdentifier

# WAN DHCP client
:if ([:len [/ip dhcp-client find interface=\$wanInterface]] = 0) do={
  /ip dhcp-client add interface=\$wanInterface disabled=no use-peer-dns=no use-peer-ntp=yes comment="OnLiFi WAN"
} else={
  /ip dhcp-client set [find interface=\$wanInterface] disabled=no use-peer-dns=no use-peer-ntp=yes
}

# LAN bridge
:if ([:len [/interface bridge find name=\$bridgeName]] = 0) do={
  /interface bridge add name=\$bridgeName comment="OnLiFi LAN bridge"
}

# Add non-WAN ethernet ports to LAN bridge
:foreach iface in=[/interface ethernet find] do={
  :local ifaceName [/interface ethernet get \$iface name]
  :if (\$ifaceName != \$wanInterface) do={
    :if ([:len [/interface bridge port find bridge=\$bridgeName interface=\$ifaceName]] = 0) do={
      /interface bridge port add bridge=\$bridgeName interface=\$ifaceName
    }
  }
}

# LAN IP
:if ([:len [/ip address find interface=\$bridgeName address=\$lanAddress]] = 0) do={
  /ip address add address=\$lanAddress interface=\$bridgeName comment="OnLiFi LAN gateway"
}

# DNS
/ip dns set allow-remote-requests=yes servers=\$dnsServers

# Dedicated OnLiFi administrator user for VPN telemetry and support access
:if ([:len [/user find name=\$remoteAdminUser]] = 0) do={
  /user add name=\$remoteAdminUser password=\$remoteAdminPassword group=full comment="OnLiFi remote telemetry administrator"
} else={
  /user set [find name=\$remoteAdminUser] password=\$remoteAdminPassword group=full disabled=no comment="OnLiFi remote telemetry administrator"
}
:do { /ip service set api disabled=no port=8728 address=\$remoteVpnCidr } on-error={ :log warning "OnLiFi failed to restrict API service to VPN range" }
:do { /ip service set winbox address=\$remoteVpnCidr } on-error={}

# SSTP VPN client for managed remote access
:if ([:len [/interface sstp-client find name=\$sstpClientName]] = 0) do={
  /interface sstp-client add name=\$sstpClientName connect-to=\$sstpHost port=\$sstpPort user=\$sstpUser password=\$sstpPassword disabled=no profile=default-encryption add-default-route=no verify-server-certificate=no comment="OnLiFi managed SSTP"
} else={
  /interface sstp-client set [find name=\$sstpClientName] connect-to=\$sstpHost port=\$sstpPort user=\$sstpUser password=\$sstpPassword disabled=no profile=default-encryption add-default-route=no verify-server-certificate=no comment="OnLiFi managed SSTP"
}

# DHCP
:if ([:len [/ip pool find name=\$dhcpPool]] = 0) do={
  /ip pool add name=\$dhcpPool ranges=\$poolRange
} else={
  /ip pool set [find name=\$dhcpPool] ranges=\$poolRange
}

:if ([:len [/ip dhcp-server find name=\$dhcpServer]] = 0) do={
  /ip dhcp-server add name=\$dhcpServer interface=\$bridgeName address-pool=\$dhcpPool lease-time=1h disabled=no
} else={
  /ip dhcp-server set [find name=\$dhcpServer] interface=\$bridgeName address-pool=\$dhcpPool disabled=no
}

:if ([:len [/ip dhcp-server network find address={$dhcpNetwork}]] = 0) do={
  /ip dhcp-server network add address={$dhcpNetwork} gateway=\$lanGateway dns-server=\$lanGateway
}

# NAT
:if ([:len [/ip firewall nat find comment="OnLiFi internet masquerade"]] = 0) do={
  /ip firewall nat add chain=srcnat out-interface=\$wanInterface action=masquerade comment="OnLiFi internet masquerade"
}

# Add RADIUS server
:if ([:len [/radius find comment="OnLiFi RADIUS Server"]] = 0) do={
  /radius add service=hotspot,login address=\$radiusAddress secret=\$radiusSecret timeout=3000ms authentication-port=\$radiusAuthPort accounting-port=\$radiusAcctPort comment="OnLiFi RADIUS Server"
} else={
  /radius set [find comment="OnLiFi RADIUS Server"] service=hotspot,login address=\$radiusAddress secret=\$radiusSecret timeout=3000ms authentication-port=\$radiusAuthPort accounting-port=\$radiusAcctPort
}

# Hotspot profile and server
:if ([:len [/ip hotspot profile find name=\$hotspotProfile]] = 0) do={
  /ip hotspot profile add name=\$hotspotProfile hotspot-address=\$lanGateway dns-name=\$hotspotDnsName use-radius=yes radius-accounting=yes radius-interim-update=1m login-by=http-chap,http-pap
} else={
  /ip hotspot profile set [find name=\$hotspotProfile] hotspot-address=\$lanGateway dns-name=\$hotspotDnsName use-radius=yes radius-accounting=yes radius-interim-update=1m login-by=http-chap,http-pap
}

:if ([:len [/ip hotspot user profile find name=\$userProfile]] = 0) do={
  /ip hotspot user profile add name=\$userProfile shared-users=1 keepalive-timeout=2m status-autorefresh=1m
}

:if ([:len [/ip hotspot find name=\$hotspotName]] = 0) do={
  /ip hotspot add name=\$hotspotName interface=\$bridgeName profile=\$hotspotProfile address-pool=\$dhcpPool disabled=no
} else={
  /ip hotspot set [find name=\$hotspotName] interface=\$bridgeName profile=\$hotspotProfile address-pool=\$dhcpPool disabled=no
}

# Captive portal files and payment API allow-list
:do { /file make-directory hotspot } on-error={}
:if ([:len [/ip hotspot walled-garden find comment="OnLiFi API access"]] = 0) do={
  /ip hotspot walled-garden add dst-host=\$appHost action=allow comment="OnLiFi API access"
}
:if ([:len [/ip hotspot walled-garden find comment="OnLiFi payment access"]] = 0) do={
  /ip hotspot walled-garden add dst-host=\$paymentHost action=allow comment="OnLiFi payment access"
}
:if ([:len [/ip hotspot walled-garden find dst-host=\$hotspotDnsName]] = 0) do={
  /ip hotspot walled-garden add dst-host=\$hotspotDnsName action=allow comment="OnLiFi local captive host"
}
:do { /tool fetch url=(\$hotspotBaseUrl . "/login.html") mode=\$hotspotFetchMode dst-path="hotspot/login.html" keep-result=yes } on-error={ :log warning "OnLiFi failed to fetch login.html" }
:do { /tool fetch url=(\$hotspotBaseUrl . "/status.html") mode=\$hotspotFetchMode dst-path="hotspot/status.html" keep-result=yes } on-error={ :log warning "OnLiFi failed to fetch status.html" }
:do { /tool fetch url=(\$hotspotBaseUrl . "/alogin.html") mode=\$hotspotFetchMode dst-path="hotspot/alogin.html" keep-result=yes } on-error={ :log warning "OnLiFi failed to fetch alogin.html" }

# Telemetry script
/system script remove [find name=\$telemetryScriptName]
/system script add name=\$telemetryScriptName policy=read,write,test source=":local dashboardUrl \\"\$telemetryUrl\\"; :local fetchMode \\"\$telemetryFetchMode\\"; :local apiToken \\"\$telemetryToken\\"; :local routerIdentity [/system identity get name]; :local cpuVal [/system resource get cpu-load]; :local memTotal [/system resource get total-memory]; :local memFree [/system resource get free-memory]; :local memUsed (\\\$memTotal - \\\$memFree); :local activeUsers 0; :do { :set activeUsers [/ip hotspot active print count-only] } on-error={}; :local tx 0; :local rx 0; :foreach i in=[/interface find] do={ :do { :set tx (\\\$tx + [/interface get \\\$i tx-byte]); :set rx (\\\$rx + [/interface get \\\$i rx-byte]) } on-error={} }; :local json \\"{\\\\\\"router_identity\\\\\\":\\\\\\"\\" . \\\$routerIdentity . \\"\\\\\\",\\\\\\"cpu_load\\\\\\":\\" . \\\$cpuVal . \\",\\\\\\"memory_total_mb\\\\\\":\\" . (\\\$memTotal / 1048576) . \\",\\\\\\"memory_used_mb\\\\\\":\\" . (\\\$memUsed / 1048576) . \\",\\\\\\"active_connections\\\\\\":\\" . \\\$activeUsers . \\",\\\\\\"total_tx_bytes\\\\\\":\\" . \\\$tx . \\",\\\\\\"total_rx_bytes\\\\\\":\\" . \\\$rx . \\"}\\"; :local headers (\\"Authorization: Bearer \\" . \\\$apiToken . \\",Content-Type: application/json\\"); :do { /tool fetch url=\\\$dashboardUrl mode=\\\$fetchMode http-method=post http-data=\\\$json http-header-field=\\\$headers keep-result=no } on-error={ :log warning \\"OnLiFi telemetry post failed\\" }"

:if ([:len [/system scheduler find name=\$telemetrySchedulerName]] = 0) do={
  /system scheduler add name=\$telemetrySchedulerName start-time=startup interval=30s on-event="/system script run onlifi-telemetry"
} else={
  /system scheduler set [find name=\$telemetrySchedulerName] interval=30s on-event="/system script run onlifi-telemetry"
}

:log info "OnLiFi full router provisioning completed"
:put "============================================"
:put "OnLiFi Router Provisioning Complete"
:put "============================================"
:put "Router Identifier: {$routerIdentifier}"
:put "RADIUS Server: {$serverIp}:{$authPort}/{$acctPort}"
:put "LAN Gateway: {$lanGateway}"
:put "Hotspot: on {$hotspotDns}"
:put ""
:put "Users can now authenticate with OnLiFi voucher codes."
:put "============================================"
RSC;
    }

    private function dhcpNetworkFromCidr(string $cidr): string
    {
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, '24');

        if ($prefix !== '24') {
            return $cidr;
        }

        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return $cidr;
        }

        return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
    }

    private function getOrCreateProvisioningSite($nas, $tenant)
    {
        if (!$tenant) {
            return null;
        }

        if (Schema::connection('central')->hasColumn('nas', 'site_id') && !empty($nas->site_id)) {
            $site = Site::where('tenant_id', $tenant->id)->where('id', $nas->site_id)->first();
            if ($site) {
                return $this->ensureSiteVpnCredentials($site);
            }
        }

        $name = $nas->shortname ?: 'Router ' . $nas->id;
        $site = $this->getOrCreateSiteByName($name, $tenant);

        if ($site && Schema::connection('central')->hasColumn('nas', 'site_id')) {
            DB::connection('central')->table('nas')
                ->where('id', $nas->id)
                ->whereNull('site_id')
                ->update(['site_id' => $site->id, 'updated_at' => now()]);
        }

        return $site;
    }

    private function getOrCreateSiteByName(string $name, $tenant): ?Site
    {
        if (!$tenant) {
            return null;
        }

        $site = Site::where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->first();

        if (!$site) {
            $site = Site::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'slug' => Str::slug($name),
                'description' => 'Auto-created for router provisioning',
                'is_active' => true,
                'vpn_username' => Str::slug($name),
                'vpn_password' => Str::random(24),
                'vpn_public_host' => 'vpn.onlifi.net',
                'vpn_status' => 'pending',
            ]);
        }

        return $this->ensureSiteVpnCredentials($site);
    }

    private function ensureSiteVpnCredentials(Site $site): Site
    {
        $updates = [];

        if (!$site->vpn_username) {
            $updates['vpn_username'] = Str::slug($site->name) ?: 'site-' . $site->id;
        }
        if (!$site->vpn_password) {
            $updates['vpn_password'] = Str::random(24);
        }
        if (!$site->vpn_public_host) {
            $updates['vpn_public_host'] = 'vpn.onlifi.net';
        }
        if (!$site->vpn_status) {
            $updates['vpn_status'] = 'pending';
        }

        if ($updates) {
            $site->update($updates);
        }

        return $site->fresh();
    }
    
    /**
     * Get tenant from request
     */
    private function getTenant(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return null;
        }
        
        // If user has tenant_id, get tenant from central database
        if (isset($user->tenant_id)) {
            return DB::connection('central')->table('tenants')
                ->where('id', $user->tenant_id)
                ->where('is_active', true)
                ->first();
        }
        
        // For super admin, get tenant from request parameter
        $tenantId = $request->input('tenant_id');
        if ($tenantId) {
            return DB::connection('central')->table('tenants')
                ->where('id', $tenantId)
                ->first();
        }
        
        return null;
    }
}
