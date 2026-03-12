<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TenantController extends Controller
{
    private $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    public function index()
    {
        $tenants = Tenant::with('users')->paginate(20);
        return response()->json($tenants);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|unique:tenants,domain',
            'admin_email' => 'required|email|unique:tenant_users,email',
            'admin_name' => 'required|string',
            'admin_password' => 'required|string|min:8',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $tenant = $this->tenantService->createTenant($request->all());

            return response()->json([
                'message' => 'Tenant created successfully',
                'tenant' => $tenant,
                'api_credentials' => [
                    'api_key' => $tenant->api_key,
                    'api_secret' => $tenant->api_secret,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create tenant',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Tenant $tenant)
    {
        $tenant->load('users');
        $stats = $this->tenantService->getTenantStats($tenant);

        return response()->json([
            'tenant' => $tenant,
            'stats' => $stats,
        ]);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'domain' => 'sometimes|string|unique:tenants,domain,' . $tenant->id,
            'is_active' => 'sometimes|boolean',
            'settings' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenant->update($request->only(['name', 'domain', 'is_active']));

        if ($request->has('settings')) {
            $this->tenantService->updateSettings($tenant, $request->settings);
        }

        return response()->json([
            'message' => 'Tenant updated successfully',
            'tenant' => $tenant->fresh(),
        ]);
    }

    public function destroy(Tenant $tenant)
    {
        try {
            $this->tenantService->deleteTenant($tenant);

            return response()->json([
                'message' => 'Tenant deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete tenant',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function suspend(Tenant $tenant)
    {
        $this->tenantService->suspendTenant($tenant);

        return response()->json([
            'message' => 'Tenant suspended successfully',
        ]);
    }

    public function activate(Tenant $tenant)
    {
        $this->tenantService->activateTenant($tenant);

        return response()->json([
            'message' => 'Tenant activated successfully',
        ]);
    }

    public function regenerateCredentials(Tenant $tenant)
    {
        $credentials = $this->tenantService->regenerateApiCredentials($tenant);

        return response()->json([
            'message' => 'API credentials regenerated successfully',
            'api_credentials' => $credentials,
        ]);
    }

    public function extendTrial(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'days' => 'required|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $this->tenantService->extendTrial($tenant, $request->days);

        return response()->json([
            'message' => 'Trial extended successfully',
            'trial_ends_at' => $tenant->fresh()->trial_ends_at,
        ]);
    }

    public function subscribe(Tenant $tenant)
    {
        $this->tenantService->subscribe($tenant);

        return response()->json([
            'message' => 'Tenant subscribed successfully',
        ]);
    }

    public function stats(Tenant $tenant)
    {
        $stats = $this->tenantService->getTenantStats($tenant);

        return response()->json($stats);
    }
}
