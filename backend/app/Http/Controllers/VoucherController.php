<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\VoucherGroup;
use App\Models\VoucherType;
use App\Services\VoucherService;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class VoucherController extends Controller
{
    private $voucherService;

    public function __construct(VoucherService $voucherService)
    {
        $this->voucherService = $voucherService;
    }

    private function resolveVoucherSite(Request $request)
    {
        $site = SiteScope::selectedOrDefaultSite($request);
        $legacySite = SiteScope::defaultSite($request);

        SiteScope::backfillLegacyTenantSite($legacySite, [
            'voucher_types',
            'voucher_sales_points',
            'voucher_groups',
            'vouchers',
            'transactions',
        ]);

        return $site;
    }

    public function index(Request $request)
    {
        $query = Voucher::with(['group', 'salesPoint']);
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'total' => 0,
                'per_page' => (int) ($request->per_page ?? 50),
            ]);
        }
        SiteScope::applyToTenantTable($query, 'vouchers', $site);

        if ($request->status === 'consumed') {
            $query->whereIn('status', ['in_use', 'used', 'expired']);
        } elseif ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('group_id')) {
            $query->where('group_id', $request->group_id);
        }

        $vouchers = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json($vouchers);
    }

    public function show($id)
    {
        $site = $this->resolveVoucherSite(request());
        if (!$site) {
            abort(404);
        }
        $query = Voucher::with(['group', 'salesPoint', 'transactions']);
        SiteScope::applyToTenantTable($query, 'vouchers', $site);
        $voucher = $query->findOrFail($id);
        return response()->json($voucher);
    }

    public function generateBatch(Request $request)
    {
        // Increase execution time for large batches
        set_time_limit(300); // 5 minutes
        ini_set('memory_limit', '512M');
        
        $validator = Validator::make($request->all(), [
            'group_name' => 'required|string|max:100',
            'profile_name' => 'required|string|max:64',
            'validity_hours' => 'required|integer|min:1',
            'validity_minutes' => 'nullable|integer|min:1',
            'price' => 'required|numeric|min:0',
            'count' => 'required|integer|min:1|max:1000',
            'description' => 'nullable|string',
            'data_limit_mb' => 'nullable|integer',
            'speed_limit_kbps' => 'nullable|integer',
            'sales_point_id' => 'nullable|integer',
            'code_format' => 'nullable|string|in:mixed,numbers,letters',
            'code_length' => 'nullable|integer|min:6|max:16',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $request->all();
            $site = $this->resolveVoucherSite($request);
            if (!$site) {
                return response()->json([
                    'error' => 'Site required',
                    'message' => 'Select a site before creating vouchers.',
                ], 422);
            }

            foreach (['voucher_groups', 'vouchers'] as $table) {
                if (!Schema::connection('tenant')->hasColumn($table, 'site_id')) {
                    return response()->json([
                        'error' => 'Tenant database needs migration',
                        'message' => "The {$table}.site_id column is required before site-specific vouchers can be created.",
                    ], 500);
                }
            }

            if ($site) {
                $data['site_id'] = $site->id;
                $data['site_name'] = $site->name;
            }

            if (!empty($data['sales_point_id']) && Schema::connection('tenant')->hasColumn('voucher_sales_points', 'site_id')) {
                $belongsToSite = DB::connection('tenant')->table('voucher_sales_points')
                    ->where('id', $data['sales_point_id'])
                    ->where('site_id', $site->id)
                    ->exists();

                if (!$belongsToSite) {
                    return response()->json([
                        'error' => 'Invalid sales point',
                        'message' => 'The selected sales point does not belong to the active site.',
                    ], 422);
                }
            }

            $result = $this->voucherService->generateVoucherBatch($data);
            return response()->json($result);
        } catch (\Exception $e) {
            \Log::error('Voucher generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'error' => 'Failed to generate vouchers',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'voucher_code' => 'required|string',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'valid' => false,
                'error' => 'Validation failed',
            ], 422);
        }

        $voucher = Voucher::where('voucher_code', $request->voucher_code)
            ->where('password', $request->password)
            ->first();

        if (!$voucher) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid voucher code or password',
            ]);
        }

        if (in_array($voucher->status, ['used', 'expired']) || ($voucher->expires_at && $voucher->expires_at->isPast())) {
            return response()->json([
                'valid' => false,
                'message' => 'Voucher has expired',
            ]);
        }

        if ($voucher->status === 'disabled') {
            return response()->json([
                'valid' => false,
                'message' => 'Voucher has been disabled',
            ]);
        }

        return response()->json([
            'valid' => true,
            'voucher' => $voucher,
        ]);
    }

    public function getTypes(Request $request)
    {
        $query = VoucherType::query();
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json(['types' => []]);
        }
        SiteScope::applyToTenantTable($query, 'voucher_types', $site);

        if (Schema::connection('tenant')->hasColumn('voucher_types', 'tenant_id') && app()->bound('tenant')) {
            $query->where(function ($q) {
                $q->where('tenant_id', app('tenant')->id)->orWhereNull('tenant_id');
            });
        }

        $types = $query->orderBy('type_name')->get()->map(function ($type) use ($site) {
            $groupQuery = VoucherGroup::query();
            SiteScope::applyToTenantTable($groupQuery, 'voucher_groups', $site);
            $groupQuery
                ->where('validity_hours', $type->duration_hours)
                ->where('price', $type->base_amount);

            if (Schema::connection('tenant')->hasColumn('voucher_groups', 'validity_minutes') && Schema::connection('tenant')->hasColumn('voucher_types', 'validity_minutes')) {
                $groupQuery->where(function ($query) use ($type) {
                    if ($type->validity_minutes) {
                        $query->where('validity_minutes', $type->validity_minutes)
                            ->orWhereNull('validity_minutes');
                    } else {
                        $query->whereNull('validity_minutes');
                    }
                });
            }

            foreach (['data_limit_mb', 'speed_limit_kbps'] as $column) {
                if ($type->{$column} === null) {
                    $groupQuery->whereNull($column);
                } else {
                    $groupQuery->where($column, $type->{$column});
                }
            }

            $groupIds = $groupQuery->pluck('id');
            $voucherQuery = Voucher::query()->whereIn('group_id', $groupIds);
            SiteScope::applyToTenantTable($voucherQuery, 'vouchers', $site);

            $type->total_vouchers = (clone $voucherQuery)->count();
            $type->unused_count = (clone $voucherQuery)->where('status', 'unused')->count();
            $type->used_count = (clone $voucherQuery)->whereIn('status', ['in_use', 'used', 'expired'])->count();

            return $type;
        });
        return response()->json(['types' => $types]);
    }

    public function storeType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type_name' => 'required|string|max:100',
            'duration_hours' => 'required|integer|min:1',
            'validity_minutes' => 'nullable|integer|min:1',
            'base_amount' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'data_limit_mb' => 'nullable|integer|min:0',
            'speed_limit_kbps' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = [
            'type_name' => $request->type_name,
            'duration_hours' => $request->duration_hours,
            'validity_minutes' => $request->validity_minutes,
            'base_amount' => $request->base_amount,
            'description' => $request->description,
            'data_limit_mb' => $request->data_limit_mb,
            'speed_limit_kbps' => $request->speed_limit_kbps,
            'is_active' => true,
        ];

        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([
                'error' => 'Site required',
                'message' => 'Select a site before creating voucher types.',
            ], 422);
        }
        if (!Schema::connection('tenant')->hasColumn('voucher_types', 'site_id')) {
            return response()->json([
                'error' => 'Tenant database needs migration',
                'message' => 'The voucher_types.site_id column is required before site-specific voucher types can be created.',
            ], 500);
        }

        if (Schema::connection('tenant')->hasColumn('voucher_types', 'tenant_id') && app()->bound('tenant')) {
            $data['tenant_id'] = app('tenant')->id;
        }
        $data = SiteScope::withSiteColumn('voucher_types', $data, $site);
        if (!Schema::connection('tenant')->hasColumn('voucher_types', 'validity_minutes')) {
            unset($data['validity_minutes']);
        }

        $type = VoucherType::create($data);

        return response()->json([
            'message' => 'Voucher type created successfully',
            'type' => $type,
        ], 201);
    }

    public function updateType(Request $request, $id)
    {
        $query = VoucherType::query();
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            abort(404);
        }
        SiteScope::applyToTenantTable($query, 'voucher_types', $site);
        $type = $query->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'type_name' => 'sometimes|string|max:100',
            'duration_hours' => 'sometimes|integer|min:1',
            'validity_minutes' => 'nullable|integer|min:1',
            'base_amount' => 'sometimes|numeric|min:0',
            'description' => 'nullable|string|max:255',
            'data_limit_mb' => 'nullable|integer|min:0',
            'speed_limit_kbps' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $updateData = $request->only([
            'type_name', 'duration_hours', 'validity_minutes', 'base_amount', 'description',
            'data_limit_mb', 'speed_limit_kbps', 'is_active'
        ]);
        if (!Schema::connection('tenant')->hasColumn('voucher_types', 'validity_minutes')) {
            unset($updateData['validity_minutes']);
        }

        $type->update($updateData);

        return response()->json([
            'message' => 'Voucher type updated successfully',
            'type' => $type->fresh(),
        ]);
    }

    public function destroyType(Request $request, $id)
    {
        $query = VoucherType::query();
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            abort(404);
        }
        SiteScope::applyToTenantTable($query, 'voucher_types', $site);
        $type = $query->findOrFail($id);
        $type->delete();

        return response()->json([
            'message' => 'Voucher type deleted successfully',
        ]);
    }

    public function getGroups(Request $request)
    {
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([]);
        }
        $query = VoucherGroup::with('salesPoint');
        SiteScope::applyToTenantTable($query, 'voucher_groups', $site);

        if (Schema::connection('tenant')->hasColumn('voucher_groups', 'tenant_id') && app()->bound('tenant')) {
            $query->where(function ($q) {
                $q->where('tenant_id', app('tenant')->id)->orWhereNull('tenant_id');
            });
        }

        $groups = $query
            ->withCount([
                'vouchers as total_vouchers' => function ($query) use ($site) {
                    SiteScope::applyToTenantTable($query, 'vouchers', $site);
                },
                'vouchers as unused_count' => function ($query) use ($site) {
                    SiteScope::applyToTenantTable($query, 'vouchers', $site);
                    $query->where('status', 'unused');
                },
                'vouchers as used_count' => function ($query) use ($site) {
                    SiteScope::applyToTenantTable($query, 'vouchers', $site);
                    $query->where('status', 'used');
                },
                'vouchers as in_use_count' => function ($query) use ($site) {
                    SiteScope::applyToTenantTable($query, 'vouchers', $site);
                    $query->whereIn('status', ['in_use', 'used', 'expired']);
                }
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($group) {
                $group->sales_point_name = $group->salesPoint?->name;
                return $group;
            });
        return response()->json($groups);
    }

    public function statistics(Request $request)
    {
        $site = $this->resolveVoucherSite($request);
        if (!$site) {
            return response()->json([
                'total_vouchers' => 0,
                'unused_vouchers' => 0,
                'in_use_vouchers' => 0,
                'reserved_vouchers' => 0,
                'used_vouchers' => 0,
                'consumed_vouchers' => 0,
                'expired_vouchers' => 0,
                'total_revenue' => 0,
                'revenue_30_days' => 0,
                'vouchers_by_status' => [],
                'daily' => [],
                'by_sales_point' => [],
            ]);
        }
        $salesPointId = $request->integer('sales_point_id') ?: null;

        if ($salesPointId && Schema::connection('tenant')->hasColumn('voucher_sales_points', 'site_id')) {
            $belongsToSite = DB::connection('tenant')->table('voucher_sales_points')
                ->where('id', $salesPointId)
                ->where('site_id', $site->id)
                ->exists();

            if (!$belongsToSite) {
                return response()->json([
                    'error' => 'Invalid sales point',
                    'message' => 'The selected sales point does not belong to the active site.',
                ], 422);
            }
        }

        $voucherQuery = Voucher::query();
        SiteScope::applyToTenantTable($voucherQuery, 'vouchers', $site);
        if ($salesPointId) {
            $voucherQuery->where('sales_point_id', $salesPointId);
        }

        // Overall statistics
        $stats = [
            'total_vouchers' => (clone $voucherQuery)->count(),
            'unused_vouchers' => (clone $voucherQuery)->unused()->count(),
            'reserved_vouchers' => (clone $voucherQuery)->where('status', 'reserved')->count(),
            'in_use_vouchers' => (clone $voucherQuery)->where('status', 'in_use')->count(),
            'used_vouchers' => (clone $voucherQuery)->used()->count(),
            'consumed_vouchers' => (clone $voucherQuery)->whereIn('status', ['in_use', 'used', 'expired'])->count(),
            'expired_vouchers' => (clone $voucherQuery)->expired()->count(),
            'total_revenue' => (clone $voucherQuery)->whereNotNull('first_used_at')->sum('price'),
            'revenue_30_days' => (clone $voucherQuery)
                ->whereNotNull('first_used_at')
                ->where('first_used_at', '>=', now()->subDays(30))
                ->sum('price'),
            'vouchers_by_status' => (clone $voucherQuery)->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
        ];

        // Daily statistics (last 30 days)
        $dailyQuery = Voucher::selectRaw('DATE(first_used_at) as date, COUNT(*) as vouchers_used, SUM(price) as revenue, COUNT(DISTINCT used_by_mac) as unique_devices');
        SiteScope::applyToTenantTable($dailyQuery, 'vouchers', $site);
        if ($salesPointId) {
            $dailyQuery->where('sales_point_id', $salesPointId);
        }
        $stats['daily'] = $dailyQuery
            ->whereNotNull('first_used_at')
            ->where('first_used_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($day) {
                return [
                    'date' => $day->date,
                    'vouchers_used' => (int) $day->vouchers_used,
                    'revenue' => (float) $day->revenue,
                    'unique_devices' => (int) $day->unique_devices,
                ];
            });

        // Statistics by sales point - use try/catch to handle missing tables gracefully
        try {
            $salesQuery = VoucherGroup::join('vouchers', 'voucher_groups.id', '=', 'vouchers.group_id')
                ->join('voucher_sales_points', 'voucher_groups.sales_point_id', '=', 'voucher_sales_points.id')
                ->selectRaw('
                    voucher_sales_points.id,
                    voucher_sales_points.name,
                    COUNT(vouchers.id) as total_vouchers,
                    SUM(CASE WHEN vouchers.status = "unused" THEN 1 ELSE 0 END) as unused,
                    SUM(CASE WHEN vouchers.status = "reserved" THEN 1 ELSE 0 END) as reserved,
                    SUM(CASE WHEN vouchers.status = "in_use" THEN 1 ELSE 0 END) as in_use,
                    SUM(CASE WHEN vouchers.status = "used" THEN 1 ELSE 0 END) as used,
                    SUM(CASE WHEN vouchers.first_used_at IS NOT NULL THEN vouchers.price ELSE 0 END) as revenue,
                    SUM(CASE WHEN vouchers.first_used_at IS NOT NULL AND vouchers.first_used_at >= ? THEN vouchers.price ELSE 0 END) as revenue_30_days
                ', [now()->subDays(30)]);
            SiteScope::applyToTenantTable($salesQuery, 'voucher_groups', $site);
            if (Schema::connection('tenant')->hasColumn('vouchers', 'site_id')) {
                $salesQuery->where('vouchers.site_id', $site->id);
            }
            if ($salesPointId) {
                $salesQuery->where('voucher_sales_points.id', $salesPointId);
            }
            $stats['by_sales_point'] = $salesQuery
                ->groupBy('voucher_sales_points.id', 'voucher_sales_points.name')
                ->get()
                ->map(function ($point) {
                    return [
                        'id' => (int) $point->id,
                        'name' => $point->name,
                        'total_vouchers' => (int) $point->total_vouchers,
                        'unused' => (int) $point->unused,
                        'reserved' => (int) $point->reserved,
                        'in_use' => (int) $point->in_use,
                        'used' => (int) $point->used,
                        'revenue' => (float) $point->revenue,
                        'revenue_30_days' => (float) $point->revenue_30_days,
                    ];
                });
        } catch (\Exception $e) {
            // If query fails (no data or table issues), return empty array
            $stats['by_sales_point'] = [];
        }

        return response()->json($stats);
    }
}
