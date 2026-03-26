<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\TenantService;
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
            'trial_ends_at' => now()->addDays(
                \App\Models\SystemSetting::get('default_trial_days', 30)
            ),
        ]);

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
            'trial_tenants' => Tenant::where('is_active', true)
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '>', now())
                ->whereNull('subscription_ends_at')
                ->count(),
            'subscribed_tenants' => Tenant::where('is_active', true)
                ->whereNotNull('subscription_ends_at')
                ->where('subscription_ends_at', '>', now())
                ->count(),
            'expired_trials' => Tenant::whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '<', now())
                ->whereNull('subscription_ends_at')
                ->count(),
        ];

        return response()->json($stats);
    }

    public function recentActivity()
    {
        $recent = [
            'recent_signups' => Tenant::orderBy('created_at', 'desc')->take(10)->get(),
            'recent_approvals' => Tenant::where('status', 'approved')
                ->orderBy('approved_at', 'desc')
                ->take(10)
                ->get(),
            'expiring_trials' => Tenant::where('is_active', true)
                ->whereNotNull('trial_ends_at')
                ->where('trial_ends_at', '>', now())
                ->where('trial_ends_at', '<', now()->addDays(7))
                ->orderBy('trial_ends_at', 'asc')
                ->get(),
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
            'trial_days' => 'sometimes|integer|min:0',
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
            
            if ($request->has('trial_days') && $request->trial_days > 0) {
                $updateData['trial_ends_at'] = now()->addDays($request->trial_days);
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
}
