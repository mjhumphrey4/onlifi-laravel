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
                'secret' => $this->radiusSharedSecret(),
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
            if ($nas->secret !== $this->radiusSharedSecret()) {
                $updates['secret'] = $this->radiusSharedSecret();
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
            'secret' => $this->radiusSharedSecret(),
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

    public function publicTelemetryScript(string $token)
    {
        $nas = DB::connection('central')->table('nas')
            ->where('provisioning_token', $token)
            ->first();

        if (!$nas) {
            return response('# Invalid or expired OnLiFi telemetry token', 404)
                ->header('Content-Type', 'text/plain');
        }

        return response($this->generateTelemetryInstallScript($nas))
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

    private function telemetryScriptUrl(string $token): string
    {
        return $this->apiBaseUrl() . "/api/router/telemetry/{$token}";
    }

    private function fetchCommand(string $token): string
    {
        $url = $this->provisioningUrl($token);
        return ":do { /file remove [find name=\"onlifi-setup.rsc\"] } on-error={}; /tool fetch url=\"{$url}\" mode={$this->fetchModeForUrl($url)} dst-path=\"onlifi-setup.rsc\" keep-result=yes; :delay 3s; :if ([:len [/file find name=\"onlifi-setup.rsc\"]] > 0) do={ /import file-name=\"onlifi-setup.rsc\" } else={ :error \"OnLiFi provisioning download failed\" }";
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

    private function radiusSharedSecret(): string
    {
        return (string) SystemSetting::get('radius_shared_secret', config('radius.shared_secret', 'onlifi_radius_secret_change_me'));
    }
    
    /**
     * Generate MikroTik RADIUS configuration script
     */
    private function generateFullProvisioningScript($nas): string
    {
        $routerIdentifier = $nas->router_identifier;
        $secret = $this->radiusSharedSecret();
        $tenant = DB::connection('central')->table('tenants')->where('id', $nas->tenant_id)->first();
        $tenantName = $tenant->name ?? 'Unknown Tenant';
        $site = $this->getOrCreateProvisioningSite($nas, $tenant);
        $siteName = $site?->name ?: ($nas->shortname ?: $tenantName);
        $siteSlug = Str::slug($siteName) ?: 'site';
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
        $hotspotDns = "{$siteSlug}.wifi";
        $remoteAdminUser = (string) SystemSetting::get('router_admin_username', 'onlifi');
        $remoteAdminPassword = (string) SystemSetting::get('router_admin_password', 'onlifi-router-admin-change-me');
        $vpnClientName = 'onlifi-sstp';
        $vpnHost = preg_replace('/:\d+$/', '', trim((string) ($site?->vpn_public_host ?: 'vpn.onlifi.net'))) ?: 'vpn.onlifi.net';
        $vpnPort = 8443;
        $vpnConnectTo = "{$vpnHost}:{$vpnPort}";
        $vpnProxy = "0.0.0.0:{$vpnPort}";
        $vpnUsername = $site?->vpn_username ?: $siteSlug;
        $vpnPassword = $site?->vpn_password ?: '';
        $vpnPrivateAddress = trim((string) ($site?->vpn_private_ip ?: ''));
        if ($vpnPrivateAddress !== '' && !str_contains($vpnPrivateAddress, '/')) {
            $vpnPrivateAddress .= '/32';
        }
        $appHost = parse_url($apiBaseUrl, PHP_URL_HOST) ?: $serverIp;
        $paymentHost = parse_url($this->manualPaymentBaseUrl(), PHP_URL_HOST) ?: 'pay.onlifi.net';
        $hotspotBaseUrl = $apiBaseUrl . "/api/captive/hotspot/{$nas->provisioning_token}";
        $portalConfigUrl = $apiBaseUrl . "/api/captive/config/{$nas->provisioning_token}";
        $loginHtmlUrl = $hotspotBaseUrl . '/login.html';
        $md5JsUrl = $hotspotBaseUrl . '/md5.js';
        $statusHtmlUrl = $hotspotBaseUrl . '/status.html';
        $aloginHtmlUrl = $hotspotBaseUrl . '/alogin.html';
        $telemetryScriptUrl = $this->telemetryScriptUrl($nas->provisioning_token);
        $hotspotFetchMode = $this->fetchModeForUrl($hotspotBaseUrl);
        $telemetryFetchMode = $this->fetchModeForUrl($telemetryUrl);
        $telemetryScriptFetchMode = $this->fetchModeForUrl($telemetryScriptUrl);
        $generatedAt = now()->toIso8601String();

        $routerIdentifier = $this->rscString($routerIdentifier);
        $secret = $this->rscString($secret);
        $tenantName = $this->rscString($tenantName);
        $lanAddress = $this->rscString($lanAddress);
        $lanGateway = $this->rscString($lanGateway);
        $dhcpNetwork = $this->rscString($dhcpNetwork);
        $poolRange = $this->rscString($poolRange);
        $dnsServers = $this->rscString($dnsServers);
        $hotspotDns = $this->rscString($hotspotDns);
        $serverIp = $this->rscString($serverIp);
        $authPort = $this->rscString((string) $authPort);
        $acctPort = $this->rscString((string) $acctPort);
        $remoteAdminUser = $this->rscString($remoteAdminUser);
        $remoteAdminPassword = $this->rscString($remoteAdminPassword);
        $vpnClientName = $this->rscString($vpnClientName);
        $vpnHost = $this->rscString($vpnHost);
        $vpnPort = $this->rscString((string) $vpnPort);
        $vpnConnectTo = $this->rscString($vpnConnectTo);
        $vpnProxy = $this->rscString($vpnProxy);
        $vpnUsername = $this->rscString($vpnUsername);
        $vpnPassword = $this->rscString($vpnPassword);
        $vpnPrivateAddress = $this->rscString($vpnPrivateAddress);
        $telemetryUrl = $this->rscString($telemetryUrl);
        $telemetryToken = $this->rscString($telemetryToken);
        $appHost = $this->rscString($appHost);
        $paymentHost = $this->rscString($paymentHost);
        $hotspotBaseUrl = $this->rscString($hotspotBaseUrl);
        $portalConfigUrl = $this->rscString($portalConfigUrl);
        $loginHtmlUrl = $this->rscString($loginHtmlUrl);
        $md5JsUrl = $this->rscString($md5JsUrl);
        $statusHtmlUrl = $this->rscString($statusHtmlUrl);
        $aloginHtmlUrl = $this->rscString($aloginHtmlUrl);
        $telemetryScriptUrl = $this->rscString($telemetryScriptUrl);
        $hotspotFetchMode = $this->rscString($hotspotFetchMode);
        $telemetryFetchMode = $this->rscString($telemetryFetchMode);
        $telemetryScriptFetchMode = $this->rscString($telemetryScriptFetchMode);

        return <<<RSC
# ============================================
# OnLiFi Full Router Provisioning Script
# ============================================
# Router Identifier: {$routerIdentifier}
# Tenant: {$tenantName}
# Generated: {$generatedAt}
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
:local dhcpNetwork "{$dhcpNetwork}"
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
:local vpnClientName "{$vpnClientName}"
:local vpnHost "{$vpnHost}"
:local vpnPort "{$vpnPort}"
:local vpnConnectTo "{$vpnConnectTo}"
:local vpnProxy "{$vpnProxy}"
:local vpnUsername "{$vpnUsername}"
:local vpnPassword "{$vpnPassword}"
:local vpnPrivateAddress "{$vpnPrivateAddress}"
:local telemetryUrl "{$telemetryUrl}"
:local telemetryToken "{$telemetryToken}"
:local appHost "{$appHost}"
:local paymentHost "{$paymentHost}"
:local hotspotBaseUrl "{$hotspotBaseUrl}"
:local portalConfigUrl "{$portalConfigUrl}"
:local loginHtmlUrl "{$loginHtmlUrl}"
:local md5JsUrl "{$md5JsUrl}"
:local statusHtmlUrl "{$statusHtmlUrl}"
:local aloginHtmlUrl "{$aloginHtmlUrl}"
:local hotspotFetchMode "{$hotspotFetchMode}"
:local telemetryFetchMode "{$telemetryFetchMode}"
:local telemetryScriptUrl "{$telemetryScriptUrl}"
:local telemetryScriptFetchMode "{$telemetryScriptFetchMode}"

:put "OnLiFi: Starting full router provisioning..."

# Download captive portal and telemetry files before changing LAN/hotspot settings.
# This keeps provisioning resilient if the router's active uplink changes during setup.
:do { /file make-directory hotspot } on-error={}
:do { /tool fetch url=\$loginHtmlUrl mode=\$hotspotFetchMode dst-path="hotspot/login.html" keep-result=yes } on-error={ :log warning "OnLiFi failed to fetch login.html before setup" }
:do { /tool fetch url=\$md5JsUrl mode=\$hotspotFetchMode dst-path="hotspot/md5.js" keep-result=yes } on-error={ :log warning "OnLiFi failed to fetch md5.js before setup" }
:do { /tool fetch url=\$statusHtmlUrl mode=\$hotspotFetchMode dst-path="hotspot/status.html" keep-result=yes } on-error={ :log warning "OnLiFi failed to fetch status.html before setup" }
:do { /tool fetch url=\$aloginHtmlUrl mode=\$hotspotFetchMode dst-path="hotspot/alogin.html" keep-result=yes } on-error={ :log warning "OnLiFi failed to fetch alogin.html before setup" }
:do { /file remove [find name="onlifi-telemetry.rsc"] } on-error={}
:do { /tool fetch url=\$telemetryScriptUrl mode=\$telemetryScriptFetchMode dst-path="onlifi-telemetry.rsc" keep-result=yes } on-error={ :log warning "OnLiFi failed to fetch telemetry installer before setup" }

# Identity
/system identity set name=\$routerIdentifier

# WAN DHCP client
:if ([:len [/ip dhcp-client find interface=\$wanInterface]] = 0) do={
  /ip dhcp-client add interface=\$wanInterface disabled=no use-peer-dns=no comment="OnLiFi WAN"
} else={
  /ip dhcp-client set [find interface=\$wanInterface] disabled=no use-peer-dns=no
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
      :do { /interface bridge port add bridge=\$bridgeName interface=\$ifaceName } on-error={ :log warning ("OnLiFi could not add " . \$ifaceName . " to bridge") }
    }
  }
}

# LAN IP
:if ([:len [/ip address find interface=\$bridgeName address=\$lanAddress]] = 0) do={
  /ip address add address=\$lanAddress interface=\$bridgeName comment="OnLiFi LAN gateway"
}

# DNS
/ip dns set allow-remote-requests=yes servers=\$dnsServers
:if ([:len [/ip dns static find name=\$hotspotDnsName]] = 0) do={
  /ip dns static add name=\$hotspotDnsName address=\$lanGateway ttl=5m comment="OnLiFi hotspot DNS"
} else={
  /ip dns static set [find name=\$hotspotDnsName] address=\$lanGateway ttl=5m comment="OnLiFi hotspot DNS"
}

# Dedicated OnLiFi administrator user for VPN telemetry and support access
:if ([:len [/user find name=\$remoteAdminUser]] = 0) do={
  /user add name=\$remoteAdminUser password=\$remoteAdminPassword group=full comment="OnLiFi remote telemetry administrator"
} else={
  /user set [find name=\$remoteAdminUser] password=\$remoteAdminPassword group=full disabled=no comment="OnLiFi remote telemetry administrator"
}
# Router management services are intentionally left unchanged.
# Winbox/API/SSH/www ports and allowed-address settings remain under the router owner's control.

# SSTP client for OnLiFi remote access.
# The SoftEther server-side user/password must match the values shown to the administrator.
:if ([:len \$vpnPassword] > 0) do={
  :if ([:len [/interface sstp-client find name=\$vpnClientName]] = 0) do={
    /interface sstp-client add name=\$vpnClientName connect-to=\$vpnConnectTo http-proxy=\$vpnProxy user=\$vpnUsername password=\$vpnPassword profile=default-encryption disabled=no comment="OnLiFi SSTP VPN"
  } else={
    /interface sstp-client set [find name=\$vpnClientName] connect-to=\$vpnConnectTo http-proxy=\$vpnProxy user=\$vpnUsername password=\$vpnPassword profile=default-encryption disabled=no comment="OnLiFi SSTP VPN"
  }
  :if ([:len \$vpnPrivateAddress] > 0) do={
    :if ([:len [/ip address find comment="OnLiFi SSTP static address"]] = 0) do={
      /ip address add address=\$vpnPrivateAddress interface=\$vpnClientName comment="OnLiFi SSTP static address"
    } else={
      /ip address set [find comment="OnLiFi SSTP static address"] address=\$vpnPrivateAddress interface=\$vpnClientName disabled=no
    }
  } else={
    :log warning "OnLiFi SSTP private IP missing; skipping static SSTP address"
  }
} else={
  :log warning "OnLiFi SSTP VPN password missing; skipping SSTP client setup"
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

:if ([:len [/ip dhcp-server network find address=\$dhcpNetwork]] = 0) do={
  /ip dhcp-server network add address=\$dhcpNetwork gateway=\$lanGateway dns-server=\$lanGateway
} else={
  /ip dhcp-server network set [find address=\$dhcpNetwork] gateway=\$lanGateway dns-server=\$lanGateway
}

# Interface lists and firewall rules needed for hotspot internet access.
# Input-chain management access is not changed.
:do { /interface list add name=WAN comment="OnLiFi WAN list" } on-error={}
:do { /interface list add name=LAN comment="OnLiFi LAN list" } on-error={}
:do {
  :if ([:len [/interface list member find list=WAN interface=\$wanInterface]] = 0) do={
    /interface list member add list=WAN interface=\$wanInterface comment="OnLiFi WAN"
  }
} on-error={}
:do {
  :if ([:len [/interface list member find list=LAN interface=\$bridgeName]] = 0) do={
    /interface list member add list=LAN interface=\$bridgeName comment="OnLiFi LAN"
  }
} on-error={}

:if ([:len [/ip firewall nat find comment="OnLiFi internet masquerade"]] = 0) do={
  /ip firewall nat add chain=srcnat out-interface=\$wanInterface action=masquerade comment="OnLiFi internet masquerade"
}
:if ([:len [/ip firewall filter find comment="OnLiFi allow established hotspot forward"]] = 0) do={
  /ip firewall filter add chain=forward action=accept connection-state=established,related comment="OnLiFi allow established hotspot forward"
}
:if ([:len [/ip firewall filter find comment="OnLiFi drop invalid hotspot forward"]] = 0) do={
  /ip firewall filter add chain=forward action=drop connection-state=invalid comment="OnLiFi drop invalid hotspot forward"
}
:if ([:len [/ip firewall filter find comment="OnLiFi allow hotspot clients to internet"]] = 0) do={
  /ip firewall filter add chain=forward in-interface=\$bridgeName out-interface=\$wanInterface action=accept comment="OnLiFi allow hotspot clients to internet"
}

# Add RADIUS server
:if ([:len [/radius find comment="OnLiFi RADIUS Server"]] = 0) do={
  /radius add service=hotspot,login address=\$radiusAddress secret=\$radiusSecret timeout=3000ms authentication-port=\$radiusAuthPort accounting-port=\$radiusAcctPort comment="OnLiFi RADIUS Server"
} else={
  /radius set [find comment="OnLiFi RADIUS Server"] service=hotspot,login address=\$radiusAddress secret=\$radiusSecret timeout=3000ms authentication-port=\$radiusAuthPort accounting-port=\$radiusAcctPort
}
:do { /radius incoming set accept=yes port=3799 } on-error={ :log warning "OnLiFi failed to enable RADIUS incoming CoA" }

# Hotspot profile and server
:do {
  :if ([:len [/ip hotspot profile find name=\$hotspotProfile]] = 0) do={
    /ip hotspot profile add name=\$hotspotProfile hotspot-address=\$lanGateway dns-name=\$hotspotDnsName html-directory=hotspot use-radius=yes radius-accounting=yes radius-interim-update=1m login-by=http-pap
  } else={
    /ip hotspot profile set [find name=\$hotspotProfile] hotspot-address=\$lanGateway dns-name=\$hotspotDnsName html-directory=hotspot use-radius=yes radius-accounting=yes radius-interim-update=1m login-by=http-pap
  }
} on-error={
  :log warning "OnLiFi hotspot profile failed with html-directory; retrying basic profile"
  :if ([:len [/ip hotspot profile find name=\$hotspotProfile]] = 0) do={
    /ip hotspot profile add name=\$hotspotProfile hotspot-address=\$lanGateway dns-name=\$hotspotDnsName use-radius=yes radius-accounting=yes radius-interim-update=1m login-by=http-pap
  } else={
    /ip hotspot profile set [find name=\$hotspotProfile] hotspot-address=\$lanGateway dns-name=\$hotspotDnsName use-radius=yes radius-accounting=yes radius-interim-update=1m login-by=http-pap
  }
}

:if ([:len [/ip hotspot user profile find name=\$userProfile]] = 0) do={
  /ip hotspot user profile add name=\$userProfile shared-users=1 keepalive-timeout=2m status-autorefresh=1m
}

:if ([:len [/ip hotspot find name=\$hotspotName]] = 0) do={
  /ip hotspot add name=\$hotspotName interface=\$bridgeName profile=\$hotspotProfile address-pool=\$dhcpPool disabled=no
} else={
  /ip hotspot set [find name=\$hotspotName] interface=\$bridgeName profile=\$hotspotProfile address-pool=\$dhcpPool disabled=no
}

# Captive portal payment API allow-list
:if ([:len [/ip hotspot walled-garden find comment="OnLiFi API access"]] = 0) do={
  /ip hotspot walled-garden add dst-host=\$appHost action=allow comment="OnLiFi API access"
}
:if ([:len [/ip hotspot walled-garden find comment="OnLiFi payment access"]] = 0) do={
  /ip hotspot walled-garden add dst-host=\$paymentHost action=allow comment="OnLiFi payment access"
}
:if ([:len [/ip hotspot walled-garden find dst-host=\$hotspotDnsName]] = 0) do={
  /ip hotspot walled-garden add dst-host=\$hotspotDnsName action=allow comment="OnLiFi local captive host"
}

# Telemetry script was fetched before network changes; import it now.
:do {
  /import file-name="onlifi-telemetry.rsc"
} on-error={
  :log warning "OnLiFi telemetry install failed; router provisioning continued"
}

:log info "OnLiFi full router provisioning completed"
:put "============================================"
:put "OnLiFi Router Provisioning Complete"
:put "============================================"
:put "Router Identifier: {$routerIdentifier}"
:put "RADIUS Server: {$serverIp}:{$authPort}/{$acctPort}"
:put "SSTP VPN: {$vpnHost}:{$vpnPort}"
:put "LAN Gateway: {$lanGateway}"
:put "Hotspot: on {$hotspotDns}"
:put ""
:put "Users can now authenticate with OnLiFi voucher codes."
:put "============================================"
RSC;
    }

    private function generateTelemetryInstallScript($nas): string
    {
        $tenant = DB::connection('central')->table('tenants')->where('id', $nas->tenant_id)->first();
        $tenantName = $tenant->name ?? 'Unknown Tenant';
        $site = $this->getOrCreateProvisioningSite($nas, $tenant);
        $siteName = $site?->name ?: ($nas->shortname ?: $tenantName);
        $apiUrl = $this->apiBaseUrl() . '/api/telemetry';
        $fetchMode = $this->fetchModeForUrl($apiUrl);
        $apiToken = $site?->api_token ?? '';
        $routerIdentifier = $nas->router_identifier ?: Str::slug($siteName) . '-ONLIFI-1';
        $generatedAt = now()->toIso8601String();

        $apiUrl = $this->rscString($apiUrl);
        $fetchMode = $this->rscString($fetchMode);
        $apiToken = $this->rscString($apiToken);
        $routerIdentifier = $this->rscString($routerIdentifier);
        $siteName = $this->rscString($siteName);

        return <<<RSC
# ============================================
# OnLiFi Router Telemetry Installer
# ============================================
# Router Identifier: {$routerIdentifier}
# Site: {$siteName}
# Generated: {$generatedAt}
# ============================================

:do { /system script remove [find name="onlifi-telemetry"] } on-error={}
:do { /system scheduler remove [find name="onlifi-telemetry-scheduler"] } on-error={}

/system script add name="onlifi-telemetry" policy=read,write,test source={
  :local dashboardUrl "{$apiUrl}"
  :local fetchMode "{$fetchMode}"
  :local apiToken "{$apiToken}"
  :local routerIdentity [/system identity get name]
  :if ([:len \$routerIdentity] = 0) do={ :set routerIdentity "{$routerIdentifier}" }

  :local cpuVal 0
  :local memTotal 0
  :local memFree 0
  :local memUsed 0
  :local activeUsers 0
  :local totalTxBytes 0
  :local totalRxBytes 0
  :local wanInterfaces ""
  :local wanCount 0
  :local routerVersion ""
  :local routerBoard ""
  :local currentTime ""
  :local currentDate ""

  :do { :set cpuVal [/system resource get cpu-load] } on-error={}
  :do { :set memTotal [/system resource get total-memory] } on-error={}
  :do { :set memFree [/system resource get free-memory] } on-error={}
  :do { :set routerVersion [/system resource get version] } on-error={}
  :do { :set routerBoard [/system resource get board-name] } on-error={}
  :do { :set activeUsers [/ip hotspot active print count-only] } on-error={}
  :do { :set currentTime [/system clock get time] } on-error={}
  :do { :set currentDate [/system clock get date] } on-error={}
  :set memUsed (\$memTotal - \$memFree)

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

  :local memTotalMb (\$memTotal / 1048576)
  :local memUsedMb (\$memUsed / 1048576)
  :local timestamp (\$currentDate . " " . \$currentTime)
  :local postData ("router_identity=" . \$routerIdentity)
  :set postData (\$postData . "&router_version=" . \$routerVersion)
  :set postData (\$postData . "&router_board=" . \$routerBoard)
  :set postData (\$postData . "&timestamp=" . \$timestamp)
  :set postData (\$postData . "&cpu_load=" . \$cpuVal)
  :set postData (\$postData . "&memory_total_mb=" . \$memTotalMb)
  :set postData (\$postData . "&memory_used_mb=" . \$memUsedMb)
  :set postData (\$postData . "&uptime_seconds=0")
  :set postData (\$postData . "&active_connections=" . \$activeUsers)
  :set postData (\$postData . "&bandwidth_download_kbps=0")
  :set postData (\$postData . "&bandwidth_upload_kbps=0")
  :set postData (\$postData . "&total_tx_bytes=" . \$totalTxBytes)
  :set postData (\$postData . "&total_rx_bytes=" . \$totalRxBytes)
  :set postData (\$postData . "&wan_interfaces=" . \$wanInterfaces)

  :do {
    /tool fetch url=\$dashboardUrl mode=\$fetchMode http-method=post http-data=\$postData http-header-field=("Authorization: Bearer " . \$apiToken . ",Content-Type: application/x-www-form-urlencoded") keep-result=no
    :log info "OnLiFi telemetry posted"
  } on-error={
    :log warning "OnLiFi telemetry post failed"
  }
}

/system scheduler add name="onlifi-telemetry-scheduler" start-time=startup interval=30s on-event="/system script run onlifi-telemetry"
:do { /system script run onlifi-telemetry } on-error={ :log warning "OnLiFi telemetry first run failed" }
:log info "OnLiFi telemetry installed"
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

    private function rscString(?string $value): string
    {
        return str_replace(
            ["\\", "\"", "\r", "\n"],
            ["\\\\", "\\\"", '', ' '],
            (string) $value
        );
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
                'site_type' => 'mikrotik',
                'is_active' => true,
                'vpn_username' => Str::slug($name),
                'vpn_password' => Str::random(24),
                'vpn_public_host' => 'vpn.onlifi.net',
                'vpn_public_port' => Site::defaultVpnPublicPort(),
                'vpn_status' => 'active',
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
        if (!$site->vpn_public_port) {
            $updates['vpn_public_port'] = Site::defaultVpnPublicPort();
        }
        if (!$site->vpn_status || $site->vpn_status === 'pending') {
            $updates['vpn_status'] = 'active';
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
