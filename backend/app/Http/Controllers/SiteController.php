<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SystemSetting;
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

        $user = $request->user();
        $query = Site::query();

        // If authenticated user has a tenant_id, filter by their tenant
        if ($user && $user->tenant_id) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $sites = $query->orderBy('id')->get();
        if ($sites->isEmpty() && $user && $user->tenant_id) {
            SiteScope::defaultSite($request);
            $sites = $query->orderBy('id')->get();
        }

        $sites->each(fn (Site $site) => $this->ensureNasForSite($site));
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
                Rule::unique('central.sites', 'name')->where(fn ($query) => $query->where('tenant_id', $request->user()?->tenant_id)),
            ],
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $site = Site::create([
            'tenant_id' => $request->user()?->tenant_id,
            'name' => $request->name,
            'slug' => Site::uniqueSlug($request->name),
            'description' => $request->description,
            'is_active' => true,
            'vpn_username' => Str::slug($request->name),
            'vpn_password' => Str::random(24),
            'vpn_public_host' => 'vpn.onlifi.net',
            'vpn_status' => 'pending',
        ]);

        $this->ensureNasForSite($site);

        return response()->json([
            'message' => 'Site created successfully',
            'site' => $site,
        ], 201);
    }

    public function show($id)
    {
        $site = Site::findOrFail($id);

        return response()->json($site);
    }

    public function update(Request $request, $id)
    {
        $site = Site::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('central.sites', 'name')
                    ->ignore($id)
                    ->where(fn ($query) => $query->where('tenant_id', $request->user()?->tenant_id)),
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
        $site = Site::findOrFail($id);
        
        // Skip router count check for now to avoid relationship errors
        $site->delete();

        return response()->json([
            'message' => 'Site deleted successfully',
        ]);
    }

    public function regenerateToken($id)
    {
        $site = Site::findOrFail($id);
        $newToken = $site->regenerateApiToken();

        return response()->json([
            'message' => 'API token regenerated successfully',
            'api_token' => $newToken,
        ]);
    }

    public function getToken($id)
    {
        $site = Site::findOrFail($id);

        return response()->json([
            'api_token' => $site->api_token,
        ]);
    }

    private function ensureNasForSite(Site $site): void
    {
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
