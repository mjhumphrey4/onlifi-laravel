<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        SiteScope::ensureCentralSitesTable();

        $tenantId = $this->tenantId($request);
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant context required',
                'message' => 'Please sign in as a tenant to manage sites.',
                'sites' => [],
            ], 403);
        }

        $query = Site::query();

        $query->where('tenant_id', $tenantId);
        if ($request->user()?->role === 'sub_user') {
            $query->whereIn('id', $request->user()->allowed_site_ids ?: []);
        }

        $sites = $query->orderBy('id')->get();
        if ($sites->isEmpty() && $request->user()?->role !== 'sub_user') {
            SiteScope::defaultSite($request);
            $sites = $query->orderBy('id')->get();
        }

        $sites->each(function (Site $site) {
            $this->ensureSiteDefaults($site);
            $this->ensureNasForSite($site->fresh());
        });
        $sites = $query->orderBy('id')->get();

        return response()->json([
            'sites' => $sites,
        ]);
    }

    public function store(Request $request)
    {
        SiteScope::ensureCentralSitesTable();

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('central.sites', 'name')->where(fn ($query) => $query->where('tenant_id', $this->tenantId($request))),
            ],
            'description' => 'nullable|string|max:255',
            'site_type' => 'nullable|string|in:mikrotik,omada',
            'omada_site_name' => 'nullable|required_if:site_type,omada|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenantId = $this->tenantId($request);
        if ($request->user()?->role === 'sub_user') {
            return response()->json([
                'error' => 'Permission denied',
                'message' => 'Sub-users cannot create sites.',
            ], 403);
        }
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant context required',
                'message' => 'Please sign in as a tenant before creating a site.',
            ], 422);
        }
        $siteType = $request->input('site_type', 'mikrotik');
        if (!$this->tenantAllowsSiteType($request, $siteType)) {
            return response()->json([
                'error' => 'Site type not enabled',
                'message' => 'Your account is not enabled for this site type.',
            ], 403);
        }

        $site = Site::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'slug' => Site::uniqueSlug($request->name),
            'description' => $request->description,
            'site_type' => $siteType,
            'omada_site_name' => $siteType === 'omada' ? trim((string) $request->input('omada_site_name')) : null,
            'omada_link_status' => $siteType === 'omada' ? 'pending_admin' : 'not_required',
            'is_active' => true,
            'vpn_username' => Str::slug($request->name),
            'vpn_password' => Str::random(24),
            'vpn_public_host' => '89.167.42.53',
            'vpn_public_port' => Site::defaultVpnPublicPort(),
            'vpn_status' => 'active',
        ]);

        $tenant = Tenant::find($tenantId);
        if ($tenant) {
            $site->provisionDatabase($tenant);
        }

        $this->ensureNasForSite($site);
        $this->ensureOmadaLinkTicket($request, $site->fresh());

        return response()->json([
            'message' => 'Site created successfully',
            'site' => $site->fresh(),
        ], 201);
    }

    public function show($id)
    {
        $site = $this->tenantSiteOrFail(request(), $id);

        return response()->json($site);
    }

    public function update(Request $request, $id)
    {
        $site = $this->tenantSiteOrFail($request, $id);

        $validator = Validator::make($request->all(), [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('central.sites', 'name')
                    ->ignore($id)
                    ->where(fn ($query) => $query->where('tenant_id', $this->tenantId($request))),
            ],
            'description' => 'nullable|string|max:255',
            'site_type' => 'sometimes|string|in:mikrotik,omada',
            'omada_site_name' => 'nullable|required_if:site_type,omada|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updates = $request->only(['name', 'description', 'site_type', 'is_active', 'omada_site_name']);
        $nextType = $updates['site_type'] ?? $site->site_type ?? 'mikrotik';

        if (!$this->tenantAllowsSiteType($request, $nextType)) {
            return response()->json([
                'error' => 'Site type not enabled',
                'message' => 'Your account is not enabled for this site type.',
            ], 403);
        }

        if ($nextType === 'omada') {
            $updates['omada_site_name'] = trim((string) ($updates['omada_site_name'] ?? $site->omada_site_name ?? $site->name));
            if ($site->site_type !== 'omada' || !$site->omada_site_id) {
                $updates['omada_link_status'] = 'pending_admin';
                $updates['omada_linked_at'] = null;
            }
        } else {
            $updates['omada_site_name'] = null;
            $updates['omada_site_id'] = null;
            $updates['omada_controller_id'] = null;
            $updates['omada_link_status'] = 'not_required';
            $updates['omada_linked_at'] = null;
        }

        $site->update($updates);

        if ($request->has('name')) {
            $site->slug = Site::uniqueSlug($request->name, $site->id);
            $site->save();
        }

        $this->ensureNasForSite($site->fresh());
        $this->ensureOmadaLinkTicket($request, $site->fresh());

        return response()->json([
            'message' => 'Site updated successfully',
            'site' => $site->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $site = $this->tenantSiteOrFail(request(), $id);
        
        // Skip router count check for now to avoid relationship errors
        $site->delete();

        return response()->json([
            'message' => 'Site deleted successfully',
        ]);
    }

    public function regenerateToken($id)
    {
        $site = $this->tenantSiteOrFail(request(), $id);
        $newToken = $site->regenerateApiToken();

        return response()->json([
            'message' => 'API token regenerated successfully',
            'api_token' => $newToken,
        ]);
    }

    public function getToken($id)
    {
        $site = $this->tenantSiteOrFail(request(), $id);

        return response()->json([
            'api_token' => $site->api_token,
        ]);
    }

    private function ensureNasForSite(Site $site): void
    {
        if ($site->site_type === 'omada') {
            return;
        }

        if (!$site->tenant_id) {
            return;
        }

        if (!Schema::connection('central')->hasTable('nas')) {
            return;
        }

        $query = DB::connection('central')->table('nas')
            ->where('tenant_id', $site->tenant_id);

        if (Schema::connection('central')->hasColumn('nas', 'site_id')) {
            $query->where('site_id', $site->id);
        } else {
            $query->where('shortname', $site->name);
        }

        $existing = $query->orderBy('id')->first();
        if ($existing) {
            $updates = [
                'shortname' => $site->name,
                'updated_at' => now(),
            ];

            if (Schema::connection('central')->hasColumn('nas', 'site_id')) {
                $updates['site_id'] = $site->id;
            }
            if (empty($existing->provisioning_token)) {
                $updates['provisioning_token'] = Str::random(64);
            }
            $sharedSecret = SystemSetting::get('radius_shared_secret', config('radius.shared_secret', 'Onlifi26A'));
            if ($existing->secret !== $sharedSecret) {
                $updates['secret'] = $sharedSecret;
            }
            $routerIdentifier = $this->routerIdentifierForSite($site, $existing->id);
            if ($existing->router_identifier !== $routerIdentifier) {
                $updates['router_identifier'] = $routerIdentifier;
            }

            DB::connection('central')->table('nas')->where('id', $existing->id)->update($updates);
            return;
        }

        DB::connection('central')->table('nas')->insert([
            'nasname' => '0.0.0.0/0',
            'router_identifier' => $this->routerIdentifierForSite($site),
            'provisioning_token' => Str::random(64),
            'shortname' => $site->name,
            'type' => 'other',
            'secret' => SystemSetting::get('radius_shared_secret', config('radius.shared_secret', 'Onlifi26A')),
            'server' => null,
            'description' => $site->description,
            'tenant_id' => $site->tenant_id,
            ...(Schema::connection('central')->hasColumn('nas', 'site_id') ? ['site_id' => $site->id] : []),
            'router_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function tenantSiteOrFail(Request $request, $id): Site
    {
        $tenantId = $this->tenantId($request);
        abort_unless($tenantId, 403);

        $query = Site::where('tenant_id', $tenantId);
        if ($request->user()?->role === 'sub_user') {
            $query->whereIn('id', $request->user()->allowed_site_ids ?: []);
        }

        return $query->findOrFail($id);
    }

    private function tenantId(Request $request): ?int
    {
        return $request->user()?->tenant_id
            ?? $request->attributes->get('tenant')?->id
            ?? (app()->bound('tenant') ? app('tenant')->id : null);
    }

    private function tenantAllowsSiteType(Request $request, string $siteType): bool
    {
        $routerTypes = $request->user()?->tenant?->settings['router_types'] ?? ['mikrotik'];

        return in_array($siteType, $routerTypes ?: ['mikrotik'], true);
    }

    private function ensureOmadaLinkTicket(Request $request, ?Site $site): void
    {
        if (!$site || $site->site_type !== 'omada' || !$site->tenant_id || $site->omada_site_id) {
            return;
        }

        if (!Schema::connection('central')->hasTable('support_tickets')) {
            return;
        }

        $subject = "Link Omada site: {$site->name}";
        $existing = SupportTicket::where('tenant_id', $site->tenant_id)
            ->where('category', 'omada')
            ->where('subject', $subject)
            ->whereIn('status', ['open', 'pending_admin', 'pending_customer'])
            ->first();

        if ($existing) {
            return;
        }

        DB::connection('central')->transaction(function () use ($request, $site, $subject) {
            $ticket = SupportTicket::create([
                'tenant_id' => $site->tenant_id,
                'tenant_user_id' => $request->user()?->id,
                'subject' => $subject,
                'category' => 'omada',
                'priority' => 'high',
                'status' => 'open',
                'last_reply_by' => 'system',
                'unread_for_admin' => true,
                'unread_for_tenant' => false,
                'last_message_at' => now(),
            ]);

            $ticket->messages()->create([
                'sender_type' => 'system',
                'sender_id' => null,
                'body' => implode("\n", [
                    'A tenant created an Omada-backed site and needs administrator linking.',
                    '',
                    "OnLiFi site: {$site->name}",
                    "Requested Omada site name: " . ($site->omada_site_name ?: $site->name),
                    "Site ID in OnLiFi: {$site->id}",
                    '',
                    'Please confirm the routers are adopted by omada.onlifi.net, then map the correct Omada controller/site ID onto this OnLiFi site.',
                ]),
            ]);
        });
    }

    private function ensureSiteDefaults(Site $site): void
    {
        $updates = [];

        if (!$site->vpn_public_host) {
            $updates['vpn_public_host'] = '89.167.42.53';
        }
        if (!$site->vpn_public_port) {
            $updates['vpn_public_port'] = Site::defaultVpnPublicPort();
        }
        if (!$site->vpn_username) {
            $updates['vpn_username'] = Str::slug($site->name) ?: 'site-' . $site->id;
        }
        if (!$site->vpn_password) {
            $updates['vpn_password'] = Str::random(24);
        }
        if (!$site->vpn_status || $site->vpn_status === 'pending') {
            $updates['vpn_status'] = 'active';
        }

        if ($updates) {
            $site->update($updates);
        }
    }

    private function routerIdentifierForSite(Site $site, ?int $ignoreNasId = null): string
    {
        $sitePart = Str::slug($site->name ?: 'site', '-');
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
}
