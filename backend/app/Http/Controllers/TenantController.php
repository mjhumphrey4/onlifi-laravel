<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantService;
use App\Services\EmailNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    private $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    public function index(Request $request)
    {
        $query = Tenant::with('users');
        
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('domain', 'like', "%{$search}%")
                  ->orWhereHas('users', function ($uq) use ($search) {
                      $uq->where('email', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->filled('router_type') && in_array($request->router_type, ['mikrotik', 'omada'], true)) {
            $query->whereRaw("JSON_CONTAINS(JSON_EXTRACT(settings, '$.router_types'), ?)", [json_encode($request->router_type)]);
        }
        
        $tenants = $query->orderBy('created_at', 'desc')->paginate(20);
        
        // Add computed fields for frontend
        $tenants->getCollection()->transform(function ($tenant) {
            $tenant->primary_email = $tenant->users->first()?->email;
            $tenant->database = $tenant->database_name;
            $tenant->billing = $tenant->billingStatus();
            $tenant->sms_credits = $tenant->smsWallet?->credits ?? 0;
            $tenant->sms_enabled = (bool) $tenant->sms_enabled;
            $tenant->mobile_money_provider = $tenant->settings['mobile_money_provider'] ?? 'yo';
            $tenant->router_types = $tenant->settings['router_types'] ?? ['mikrotik'];
            $tenant->signup_site_name = $tenant->settings['signup_site_name'] ?? null;
            return $tenant;
        });
        
        return response()->json($tenants);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'domain' => ['nullable', 'string', Rule::unique('central.tenants', 'domain')],
            'admin_email' => ['required', 'email', Rule::unique('central.tenant_users', 'email')],
            'admin_name' => 'required|string',
            'admin_password' => 'required|string|min:8',
            'site_name' => 'required|string|max:100',
            'mobile_money_provider' => 'nullable|string|in:yo,iotec',
            'router_types' => 'nullable|array|min:1',
            'router_types.*' => 'string|in:mikrotik,omada',
            'sms_enabled' => 'nullable|boolean',
            'settings' => 'nullable|array',
        ]);

        $validator->after(function ($validator) use ($request) {
            $slug = \Illuminate\Support\Str::slug((string) $request->input('name'));
            if ($slug && Tenant::where('slug', $slug)->exists()) {
                $validator->errors()->add('name', 'This username is already taken.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $tenant = $this->tenantService->createTenant($request->all());
            app(EmailNotificationService::class)->sendSignupReceived($tenant);

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

        $startsAt = $tenant->trial_ends_at && $tenant->trial_ends_at->greaterThan(now())
            ? $tenant->trial_ends_at->copy()
            : now();

        $tenant->update([
            'trial_ends_at' => $startsAt->addDays((int) $request->days),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Trial extended successfully',
            'tenant' => $tenant->fresh(),
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

    public function stats(Tenant $tenant)
    {
        $stats = $this->tenantService->getTenantStats($tenant);

        return response()->json($stats);
    }
}
