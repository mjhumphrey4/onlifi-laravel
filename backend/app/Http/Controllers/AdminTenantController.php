<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\PlatformFee;
use App\Models\RadiusNas;
use App\Models\SmsWallet;
use App\Services\TenantService;
use App\Services\EmailNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminTenantController extends Controller
{
    private $tenantService;

    public function __construct(TenantService $tenantService)
    {
        $this->tenantService = $tenantService;
    }

    public function pending()
    {
        $tenants = Tenant::where('status', 'pending')
            ->with('users')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $tenants->getCollection()->transform(function (Tenant $tenant) {
            $tenant->mobile_money_provider = $tenant->settings['mobile_money_provider'] ?? 'yo';
            $tenant->router_types = $tenant->settings['router_types'] ?? ['mikrotik'];
            $tenant->signup_site_name = $tenant->settings['signup_site_name'] ?? null;
            return $tenant;
        });

        return response()->json($tenants);
    }

    public function approve(Request $request, Tenant $tenant)
    {
        if ($tenant->status !== 'pending') {
            return response()->json([
                'error' => 'Invalid status',
                'message' => 'Only pending tenants can be approved',
            ], 400);
        }

        $admin = $request->user();
        $dbProvisioningWarning = null;
        // Try to provision database first, before marking as approved
        try {
            $tenant->provisionDatabase();
            $tenant->runMigrations();
            $this->tenantService->ensureDefaultSite($tenant);
        } catch (\Exception $e) {
            // Log the error but continue with approval
            \Log::warning("Database provisioning failed for tenant {$tenant->id}: " . $e->getMessage());
            $dbProvisioningWarning = "Database provisioning failed: " . $e->getMessage() . 
                ". The tenant has been approved but may need manual database setup.";
        }

        // Now mark as approved
        $tenant->update([
            'status' => 'approved',
            'is_active' => true,
            'approved_at' => now(),
            'approved_by' => $admin->id,
            'trial_ends_at' => null,
            'subscription_ends_at' => null,
        ]);

        try {
            $this->tenantService->ensureDefaultSite($tenant->fresh());
        } catch (\Exception $e) {
            \Log::warning("Default site creation failed for tenant {$tenant->id}: " . $e->getMessage());
            $dbProvisioningWarning = trim(($dbProvisioningWarning ? $dbProvisioningWarning . ' ' : '') . 'Default site creation failed: ' . $e->getMessage());
        }

        app(EmailNotificationService::class)->sendActivationConfirmation($tenant->fresh('users'));

        $response = [
            'message' => 'Tenant approved successfully',
            'tenant' => $tenant->fresh()->load('users'),
        ];

        if ($dbProvisioningWarning) {
            $response['warning'] = $dbProvisioningWarning;
        }

        return response()->json($response);
    }

    public function reject(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($tenant->status !== 'pending') {
            return response()->json([
                'error' => 'Invalid status',
                'message' => 'Only pending tenants can be rejected',
            ], 400);
        }

        $admin = $request->user();

        $tenant->update([
            'status' => 'rejected',
            'is_active' => false,
            'approved_by' => $admin->id,
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'message' => 'Tenant rejected successfully',
            'tenant' => $tenant->fresh(),
        ]);
    }

    public function statistics()
    {
        $stats = [
            'total_tenants' => Tenant::count(),
            'pending_tenants' => Tenant::where('status', 'pending')->count(),
            'approved_tenants' => Tenant::where('status', 'approved')->count(),
            'rejected_tenants' => Tenant::where('status', 'rejected')->count(),
            'suspended_tenants' => Tenant::where('status', 'suspended')->count(),
            'active_tenants' => Tenant::where('is_active', true)->count(),
            'approved_active_tenants' => Tenant::where('status', 'approved')->where('is_active', true)->count(),
            'tenants_with_fee_overrides' => Tenant::whereNotNull('collection_fee_percent')
                ->orWhereNotNull('disbursement_fee_percent')
                ->orWhereNotNull('minimum_disbursement')
                ->count(),
            'registered_radius_routers' => RadiusNas::count(),
            'platform_fees_collected' => PlatformFee::getTotalPlatformFees(),
            'yo_payment_tenants' => Tenant::whereRaw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mobile_money_provider')) = 'yo'")->count(),
            'iotec_payment_tenants' => Tenant::whereRaw("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mobile_money_provider')) = 'iotec'")->count(),
            'mikrotik_tenants' => Tenant::whereRaw("JSON_CONTAINS(JSON_EXTRACT(settings, '$.router_types'), ?)", [json_encode('mikrotik')])->count(),
            'omada_tenants' => Tenant::whereRaw("JSON_CONTAINS(JSON_EXTRACT(settings, '$.router_types'), ?)", [json_encode('omada')])->count(),
        ];

        return response()->json($stats);
    }

    public function repairTenant(Request $request, Tenant $tenant)
    {
        $actions = [];
        $warnings = [];

        try {
            $tenant->provisionDatabase();
            $actions[] = 'database_provisioned';
        } catch (\Exception $e) {
            $warnings[] = 'Database provisioning: ' . $e->getMessage();
        }

        try {
            $tenant->runMigrations();
            $actions[] = 'migrations_ran';
        } catch (\Exception $e) {
            $warnings[] = 'Tenant migrations: ' . $e->getMessage();
        }

        if (!$tenant->api_key || !$tenant->api_secret) {
            $credentials = $this->tenantService->regenerateApiCredentials($tenant);
            $actions[] = 'api_credentials_regenerated';
        }

        if ($request->boolean('activate') && $tenant->status !== 'approved') {
            $tenant->update([
                'status' => 'approved',
                'is_active' => true,
                'approved_at' => now(),
                'approved_by' => $request->user()?->id,
                'trial_ends_at' => null,
                'subscription_ends_at' => null,
            ]);
            $actions[] = 'tenant_activated';
        }

        try {
            $this->tenantService->ensureDefaultSite($tenant);
            $actions[] = 'default_site_ensured';
        } catch (\Exception $e) {
            $warnings[] = 'Default site: ' . $e->getMessage();
        }

        return response()->json([
            'message' => empty($warnings) ? 'Tenant repair completed' : 'Tenant repair completed with warnings',
            'tenant' => $tenant->fresh()->load('users'),
            'actions' => $actions,
            'warnings' => $warnings,
        ]);
    }

    public function recentActivity()
    {
        $recent = [
            'recent_signups' => Tenant::orderBy('created_at', 'desc')->take(10)->get(),
            'recent_approvals' => Tenant::where('status', 'approved')
                ->orderBy('approved_at', 'desc')
                ->take(10)
                ->get(),
            'expiring_trials' => collect(),
            'recent_repairs' => [],
        ];

        return response()->json($recent);
    }

    public function viewDatabase(Tenant $tenant)
    {
        if ($tenant->status !== 'approved') {
            return response()->json([
                'error' => 'Database not available',
                'message' => 'Only approved tenants have databases',
            ], 400);
        }

        try {
            $tables = \DB::connection('tenant')
                ->select("SHOW TABLES FROM {$tenant->database_name}");

            $tableNames = array_map(function ($table) use ($tenant) {
                $key = "Tables_in_{$tenant->database_name}";
                return $table->$key;
            }, $tables);

            $tableInfo = [];
            foreach ($tableNames as $tableName) {
                $count = \DB::connection('tenant')
                    ->table($tableName)
                    ->count();

                $tableInfo[] = [
                    'name' => $tableName,
                    'row_count' => $count,
                ];
            }

            return response()->json([
                'database_name' => $tenant->database_name,
                'tables' => $tableInfo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to retrieve database info',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function queryDatabase(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if ($tenant->status !== 'approved') {
            return response()->json([
                'error' => 'Database not available',
                'message' => 'Only approved tenants have databases',
            ], 400);
        }

        $query = trim($request->query);
        
        if (!preg_match('/^SELECT/i', $query)) {
            return response()->json([
                'error' => 'Invalid query',
                'message' => 'Only SELECT queries are allowed',
            ], 400);
        }

        try {
            $tenant->configure();
            
            $results = \DB::connection('tenant')->select($query);

            return response()->json([
                'results' => $results,
                'count' => count($results),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Query failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function resetPassword(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Get the primary user for this tenant
            $user = $tenant->users()->first();
            
            if (!$user) {
                return response()->json([
                    'error' => 'No user found',
                    'message' => 'This tenant has no associated users',
                ], 404);
            }

            $user->update([
                'password' => bcrypt($request->password),
            ]);
            app(EmailNotificationService::class)->sendPasswordResetNotice($tenant->fresh('users'));

            return response()->json([
                'message' => 'Password reset successfully',
                'user_email' => $user->email,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Password reset failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateTenant(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'domain' => 'sometimes|nullable|string|max:255',
            'support_notes' => 'sometimes|nullable|string|max:5000',
            'trial_ends_at' => 'sometimes|nullable|date',
            'subscription_ends_at' => 'sometimes|nullable|date',
            'sms_enabled' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $updateData = [];
            
            if ($request->has('name')) {
                $updateData['name'] = $request->name;
            }
            
            if ($request->has('domain')) {
                $updateData['domain'] = $request->domain;
            }
            
            if ($request->has('support_notes')) {
                $updateData['support_notes'] = $request->support_notes;
            }

            if ($request->has('trial_ends_at')) {
                $updateData['trial_ends_at'] = $request->trial_ends_at;
            }

            if ($request->has('subscription_ends_at')) {
                $updateData['subscription_ends_at'] = $request->subscription_ends_at;
            }

            if ($request->has('sms_enabled')) {
                $updateData['sms_enabled'] = $request->boolean('sms_enabled');
            }

            $tenant->update($updateData);

            return response()->json([
                'message' => 'Tenant updated successfully',
                'tenant' => $tenant->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Update failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function adjustSmsCredits(Request $request, Tenant $tenant)
    {
        $validator = Validator::make($request->all(), [
            'credits' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $wallet = SmsWallet::firstOrCreate(['tenant_id' => $tenant->id], ['credits' => 0]);
        $newBalance = max(0, $wallet->credits + (int) $request->credits);
        $wallet->update(['credits' => $newBalance]);

        return response()->json([
            'message' => 'SMS credits adjusted successfully',
            'credits' => $wallet->fresh()->credits,
        ]);
    }
}
