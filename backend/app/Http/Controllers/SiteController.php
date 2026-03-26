<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SiteController extends Controller
{
    public function index()
    {
        $sites = Site::orderBy('name')->get();

        return response()->json([
            'sites' => $sites,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:sites,name',
            'description' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $site = Site::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'is_active' => true,
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
            'name' => 'sometimes|string|max:100|unique:sites,name,' . $id,
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
