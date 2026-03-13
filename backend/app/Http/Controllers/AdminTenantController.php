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

        try {
            $admin = $request->user();

            $tenant->update([
                'status' => 'approved',
                'is_active' => true,
                'approved_at' => now(),
                'approved_by' => $admin->id,
                'trial_ends_at' => now()->addDays(
                    \App\Models\SystemSetting::get('default_trial_days', 30)
                ),
            ]);

            $tenant->provisionDatabase();
            $tenant->runMigrations();

            return response()->json([
                'message' => 'Tenant approved successfully',
                'tenant' => $tenant->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Approval failed',
                'message' => $e->getMessage(),
            ], 500);
        }
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
}
