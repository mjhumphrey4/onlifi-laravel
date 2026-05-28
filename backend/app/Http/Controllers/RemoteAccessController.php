<?php

namespace App\Http\Controllers;

use App\Models\Site;
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

        return response()->json([
            'vpn_host' => 'vpn.onlifi.net',
            'sites' => $sites->map(fn (array $site) => [
                'id' => $site['id'],
                'name' => $site['name'],
                'slug' => $site['slug'],
                'vpn_public_endpoint' => $site['vpn_public_endpoint'],
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $site->update($request->only([
            'vpn_private_ip',
            'vpn_username',
            'vpn_password',
            'vpn_public_host',
            'vpn_public_port',
            'vpn_status',
            'vpn_last_seen_at',
            'router_api_port',
            'remote_access_notes',
        ]));

        $this->syncTenantRouterRecord($tenant, $site->fresh());

        return response()->json([
            'message' => 'Remote access details updated',
            'site' => $this->formatSite($site->fresh()),
        ]);
    }

    private function syncTenantRouterRecord(Tenant $tenant, Site $site): void
    {
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

    private function formatSite(Site $site): array
    {
        return [
            'id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'name' => $site->name,
            'slug' => $site->slug,
            'description' => $site->description,
            'vpn_private_ip' => $site->vpn_private_ip,
            'vpn_username' => $site->vpn_username ?: $this->defaultVpnUsername($site),
            'vpn_password' => $site->vpn_password,
            'vpn_public_host' => $site->vpn_public_host ?: 'vpn.onlifi.net',
            'vpn_public_port' => $site->vpn_public_port,
            'vpn_public_endpoint' => $this->publicEndpoint($site),
            'vpn_status' => $site->vpn_status ?: 'active',
            'vpn_last_seen_at' => $site->vpn_last_seen_at?->toIso8601String(),
            'router_api_port' => $site->router_api_port ?: 8728,
            'remote_access_notes' => $site->remote_access_notes,
        ];
    }

    private function publicEndpoint(Site $site): ?string
    {
        if (!$site->vpn_public_port) {
            return null;
        }

        return ($site->vpn_public_host ?: 'vpn.onlifi.net') . ':' . $site->vpn_public_port;
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
            $updates['vpn_public_host'] = 'vpn.onlifi.net';
        }
        if (!$site->vpn_public_port) {
            $updates['vpn_public_port'] = Site::uniqueVpnPublicPort($site->id);
        }
        if (!$site->vpn_status || $site->vpn_status === 'pending') {
            $updates['vpn_status'] = 'active';
        }

        if ($updates) {
            $site->update($updates);
            return $site->fresh();
        }

        return $site;
    }
}
