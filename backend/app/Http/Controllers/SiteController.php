<?php

namespace App\Http\Controllers;

use App\Models\Site;
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

        $sites = $query->orderBy('id')->get();
        if ($sites->isEmpty()) {
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenantId = $this->tenantId($request);
        if (!$tenantId) {
            return response()->json([
                'error' => 'Tenant context required',
                'message' => 'Please sign in as a tenant before creating a site.',
            ], 422);
        }

        $site = Site::create([
            'tenant_id' => $tenantId,
            'name' => $request->name,
            'slug' => Site::uniqueSlug($request->name),
            'description' => $request->description,
            'is_active' => true,
            'vpn_username' => Str::slug($request->name),
            'vpn_password' => Str::random(24),
            'vpn_public_host' => 'vpn.onlifi.net',
            'vpn_public_port' => Site::uniqueVpnPublicPort(),
            'vpn_status' => 'active',
        ]);

        $tenant = Tenant::find($tenantId);
        if ($tenant) {
            $site->provisionDatabase($tenant);
        }

        $this->ensureNasForSite($site);

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
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $site->update($request->only(['name', 'description', 'is_active']));

        if ($request->has('name')) {
            $site->slug = Site::uniqueSlug($request->name, $site->id);
            $site->save();
        }

        $this->ensureNasForSite($site->fresh());

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
            'secret' => SystemSetting::get('radius_shared_secret', config('radius.shared_secret', 'onlifi_radius_secret')),
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

        return Site::where('tenant_id', $tenantId)->findOrFail($id);
    }

    private function tenantId(Request $request): ?int
    {
        return $request->user()?->tenant_id
            ?? $request->attributes->get('tenant')?->id
            ?? (app()->bound('tenant') ? app('tenant')->id : null);
    }

    private function ensureSiteDefaults(Site $site): void
    {
        $updates = [];

        if (!$site->vpn_public_host) {
            $updates['vpn_public_host'] = 'vpn.onlifi.net';
        }
        if (!$site->vpn_public_port) {
            $updates['vpn_public_port'] = Site::uniqueVpnPublicPort($site->id);
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
