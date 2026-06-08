<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RemoteAccessController extends Controller
{
    public function tenantIndex(Request $request)
    {
        $tenantId = $request->user()?->tenant_id;
        $selectedSite = SiteScope::selectedSite($request);
        if ($tenantId) {
            SiteScope::defaultSite($request);
        }

        $sites = Site::where('tenant_id', $tenantId)
            ->when($selectedSite, fn ($query) => $query->where('id', $selectedSite->id))
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site) => $this->formatSite($this->ensureVpnDefaults($site)));

        $webLoginUrl = SystemSetting::get('remote_access_web_login_url', 'https://vpn.onlifi.net');

        return response()->json([
            'vpn_host' => $this->remoteAccessDisplayHost($webLoginUrl),
            'mobile_app_url' => SystemSetting::get('remote_access_mobile_app_url', 'https://onlifi.net/downloads/onlifi-mobile.apk'),
            'web_login_url' => $webLoginUrl,
            'sites' => $sites->map(fn (array $site) => [
                'id' => $site['id'],
                'name' => $site['name'],
                'slug' => $site['slug'],
                'remote_access_port' => $site['remote_access_port'],
                'remote_access_endpoint' => $this->remoteAccessDisplayEndpoint($webLoginUrl, $site['remote_access_port']),
                'vpn_public_endpoint' => $this->remoteAccessDisplayEndpoint($webLoginUrl, $site['remote_access_port']),
                'vpn_status' => $site['vpn_status'],
            ]),
        ]);
    }

    public function adminIndex(Tenant $tenant)
    {
        $this->ensureTenantDefaultSite($tenant);

        $sites = Site::where('tenant_id', $tenant->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site) => $this->formatSite($this->ensureVpnDefaults($site)));

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
            'vpn_range' => SystemSetting::get('router_remote_vpn_cidr', '10.10.1.0/24'),
            'wireguard_endpoint' => $this->wireGuardEndpoint(),
            'remote_access_host' => $this->remoteAccessDisplayHost(SystemSetting::get('remote_access_web_login_url', 'https://vpn.onlifi.net')),
            'wireguard_server_public_key_configured' => (bool) SystemSetting::get('wireguard_server_public_key', ''),
            'router_admin_username' => SystemSetting::get('router_admin_username', 'onlifi'),
            'sites' => $sites,
        ]);
    }

    public function adminUpdate(Request $request, Tenant $tenant, Site $site)
    {
        if ((int) $site->tenant_id !== (int) $tenant->id) {
            return response()->json(['message' => 'Site does not belong to this tenant'], 404);
        }

        $validator = Validator::make($request->all(), [
            'vpn_private_ip' => 'nullable|ip',
            'vpn_username' => 'nullable|string|max:100',
            'vpn_password' => 'nullable|string|max:255',
            'vpn_public_host' => 'nullable|string|max:100',
            'vpn_public_port' => 'nullable|integer|min:1|max:65535',
            'vpn_status' => 'nullable|string|in:pending,active,offline,suspended',
            'vpn_last_seen_at' => 'nullable|date',
            'router_api_port' => 'nullable|integer|min:1|max:65535',
            'remote_access_notes' => 'nullable|string|max:5000',
            'assigned_device_ip_range' => 'nullable|string|max:64',
            'omada_site_name' => 'nullable|string|max:100',
            'omada_site_id' => 'nullable|string|max:100',
            'omada_controller_id' => 'nullable|string|max:100',
            'omada_link_status' => 'nullable|string|in:not_required,pending_admin,linked,failed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updates = $request->only([
            'vpn_private_ip',
            'vpn_username',
            'vpn_password',
            'vpn_public_host',
            'vpn_status',
            'vpn_last_seen_at',
            'router_api_port',
            'remote_access_notes',
            'assigned_device_ip_range',
            'omada_site_name',
            'omada_site_id',
            'omada_controller_id',
            'omada_link_status',
        ]);
        $updates['vpn_public_port'] = Site::defaultVpnPublicPort();
        $updates['vpn_public_host'] = $updates['vpn_public_host'] ?? '89.167.42.53';

        if ($site->site_type === 'omada') {
            $updates['omada_site_name'] = trim((string) ($updates['omada_site_name'] ?? $site->omada_site_name ?? $site->name));
            $updates['omada_link_status'] = $updates['omada_link_status'] ?? ($updates['omada_site_id'] ? 'linked' : 'pending_admin');
            $updates['omada_linked_at'] = $updates['omada_link_status'] === 'linked' && !empty($updates['omada_site_id'])
                ? now()
                : null;
        } else {
            unset($updates['omada_site_name'], $updates['omada_site_id'], $updates['omada_controller_id'], $updates['omada_link_status']);
        }

        $site->update($updates);
        $this->resolveOmadaLinkTickets($site->fresh());

        $this->syncTenantRouterRecord($tenant, $site->fresh());

        return response()->json([
            'message' => 'Remote access details updated',
            'site' => $this->formatSite($site->fresh()),
        ]);
    }

    private function syncTenantRouterRecord(Tenant $tenant, Site $site): void
    {
        if ($site->site_type === 'omada') {
            return;
        }

        if (!$site->vpn_private_ip || $tenant->status !== 'approved') {
            return;
        }

        try {
            $tenant->configure();

            if (!Schema::connection('tenant')->hasTable('mikrotik_routers')) {
                return;
            }

            $updates = [
                'ip_address' => $site->vpn_private_ip,
                'api_port' => $site->router_api_port ?: 8728,
                'username' => SystemSetting::get('router_admin_username', 'onlifi'),
                'password' => SystemSetting::get('router_admin_password', 'onlifi-router-admin-change-me'),
            ];

            $query = \App\Models\MikrotikRouter::query();
            if (Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
                $query->where('site_id', $site->id);
            } else {
                $query->where('name', $site->name);
            }

            if ($query->exists()) {
                $query->update($updates);
                return;
            }

            \App\Models\MikrotikRouter::create([
                'name' => $site->name,
                ...$updates,
                ...(Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id') ? ['site_id' => $site->id] : []),
                'location' => $site->description,
                'is_active' => true,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync remote access details to tenant router record', [
                'tenant_id' => $tenant->id,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveOmadaLinkTickets(Site $site): void
    {
        if ($site->site_type !== 'omada' || !$site->omada_site_id || ($site->omada_link_status !== 'linked')) {
            return;
        }

        SupportTicket::where('tenant_id', $site->tenant_id)
            ->where('category', 'omada')
            ->where('subject', "Link Omada site: {$site->name}")
            ->whereIn('status', ['open', 'pending_admin'])
            ->update([
                'status' => 'pending_customer',
                'last_reply_by' => 'admin',
                'unread_for_admin' => false,
                'unread_for_tenant' => true,
                'last_message_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function formatSite(Site $site): array
    {
        return [
            'id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'name' => $site->name,
            'slug' => $site->slug,
            'description' => $site->description,
            'site_type' => $site->site_type ?: 'mikrotik',
            'omada_site_name' => $site->omada_site_name,
            'omada_site_id' => $site->omada_site_id,
            'omada_controller_id' => $site->omada_controller_id,
            'omada_link_status' => $site->omada_link_status ?: ($site->site_type === 'omada' ? 'pending_admin' : 'not_required'),
            'omada_linked_at' => $site->omada_linked_at?->toIso8601String(),
            'vpn_private_ip' => $site->vpn_private_ip,
            'vpn_username' => $site->vpn_username ?: $this->defaultVpnUsername($site),
            'vpn_password' => $site->vpn_password,
            'vpn_public_host' => $site->vpn_public_host ?: '89.167.42.53',
            'vpn_public_port' => Site::defaultVpnPublicPort(),
            'remote_access_port' => $site->remote_access_port,
            'remote_access_endpoint' => $this->remoteAccessDisplayEndpoint(SystemSetting::get('remote_access_web_login_url', 'https://vpn.onlifi.net'), $site->remote_access_port),
            'vpn_public_endpoint' => $this->publicEndpoint($site),
            'wireguard_public_key' => $site->wireguard_public_key,
            'wireguard_private_key' => $site->wireguard_private_key,
            'wireguard_config' => $this->wireGuardClientConfig($site),
            'wireguard_server_peer' => $this->wireGuardServerPeerConfig($site),
            'vpn_status' => $site->vpn_status ?: 'active',
            'vpn_last_seen_at' => $site->vpn_last_seen_at?->toIso8601String(),
            'router_api_port' => $site->router_api_port ?: 8728,
            'remote_access_notes' => $site->remote_access_notes,
            'assigned_device_ip_range' => $site->assigned_device_ip_range,
        ];
    }

    private function publicEndpoint(Site $site): ?string
    {
        return $this->wireGuardEndpoint();
    }

    private function remoteAccessDisplayHost(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if ($host) {
            return $host . ($port ? ':' . $port : '');
        }

        return preg_replace('#^https?://#', '', rtrim($url, '/')) ?: 'vpn.onlifi.net';
    }

    private function remoteAccessDisplayEndpoint(string $url, ?int $port): string
    {
        $host = $this->remoteAccessDisplayHost($url);
        $host = preg_replace('/:\d+$/', '', $host) ?: 'vpn.onlifi.net';

        return $port ? "{$host}:{$port}" : $host;
    }

    private function defaultVpnUsername(Site $site): string
    {
        return Str::slug($site->name) ?: 'site-' . $site->id;
    }

    private function ensureTenantDefaultSite(Tenant $tenant): void
    {
        if (Site::where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        Site::create([
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => Site::uniqueSlug($tenant->name),
            'description' => 'Default site created for remote access management.',
            'is_active' => true,
        ]);
    }

    private function ensureVpnDefaults(Site $site): Site
    {
        $updates = [];
        if (!$site->vpn_username) {
            $updates['vpn_username'] = $this->defaultVpnUsername($site);
        }
        if (!$site->vpn_password) {
            $updates['vpn_password'] = Str::random(24);
        }
        if (!$site->vpn_public_host) {
            $updates['vpn_public_host'] = '89.167.42.53';
        }
        if ((int) $site->vpn_public_port !== Site::defaultVpnPublicPort()) {
            $updates['vpn_public_port'] = Site::defaultVpnPublicPort();
        }
        if (Site::hasRemoteAccessPortColumn() && !$site->remote_access_port) {
            $updates['remote_access_port'] = Site::uniqueRemoteAccessPort($site->id);
        }
        if (!$site->vpn_status || $site->vpn_status === 'pending') {
            $updates['vpn_status'] = 'active';
        }
        if (!$site->wireguard_private_key || !$site->wireguard_public_key) {
            $keys = Site::generateWireGuardKeyPair();
            $updates['wireguard_private_key'] = $site->wireguard_private_key ?: $keys['private_key'];
            $updates['wireguard_public_key'] = $site->wireguard_public_key ?: $keys['public_key'];
        }

        if ($updates) {
            $site->update($updates);
            return $site->fresh();
        }

        return $site;
    }

    private function wireGuardEndpoint(): string
    {
        $host = SystemSetting::get('wireguard_endpoint_host', '89.167.42.53');
        $host = preg_replace('/:\d+$/', '', trim((string) $host)) ?: '89.167.42.53';

        return $host . ':' . Site::defaultVpnPublicPort();
    }

    private function wireGuardClientConfig(Site $site): string
    {
        $address = $site->vpn_private_ip ? $this->cidrAddress($site->vpn_private_ip) : '<ADMIN_ASSIGNED_IP>/32';
        $serverPublicKey = SystemSetting::get('wireguard_server_public_key', '<WIREGUARD_SERVER_PUBLIC_KEY>');
        $allowed = SystemSetting::get('wireguard_allowed_address', SystemSetting::get('router_remote_vpn_cidr', '10.10.1.0/24'));
        $dns = SystemSetting::get('wireguard_client_dns', '');
        $dnsLine = $dns ? "\nDNS = {$dns}" : '';

        return "[Interface]\nPrivateKey = {$site->wireguard_private_key}\nAddress = {$address}{$dnsLine}\n\n[Peer]\nPublicKey = {$serverPublicKey}\nEndpoint = {$this->wireGuardEndpoint()}\nAllowedIPs = {$allowed}\nPersistentKeepalive = 25";
    }

    private function wireGuardServerPeerConfig(Site $site): string
    {
        $address = $site->vpn_private_ip ? $this->cidrAddress($site->vpn_private_ip) : '<ADMIN_ASSIGNED_IP>/32';

        return "[Peer]\n# {$site->name}\nPublicKey = {$site->wireguard_public_key}\nAllowedIPs = {$address}";
    }

    private function cidrAddress(string $address): string
    {
        return str_contains($address, '/') ? $address : "{$address}/32";
    }
}
