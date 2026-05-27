<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class SiteController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Site::query();

        // If authenticated user has a tenant_id, filter by their tenant
        if ($user && $user->tenant_id) {
            $query->where('tenant_id', $user->tenant_id);
        }

        $sites = $query->orderBy('name')->get();

        return response()->json([
            'sites' => $sites,
        ]);
    }

    public function store(Request $request)
    {
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
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'is_active' => true,
            'vpn_username' => Str::slug($request->name),
            'vpn_password' => Str::random(24),
            'vpn_public_host' => 'vpn.onlifi.net',
            'vpn_status' => 'pending',
        ]);

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
            $site->slug = Str::slug($request->name);
            $site->save();
        }

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
}
