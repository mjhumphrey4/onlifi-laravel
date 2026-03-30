<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\VoucherGroup;
use App\Models\VoucherType;
use App\Services\VoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VoucherController extends Controller
{
    private $voucherService;

    public function __construct(VoucherService $voucherService)
    {
        $this->voucherService = $voucherService;
    }

    public function index(Request $request)
    {
        $query = Voucher::with(['group', 'salesPoint']);

        if ($request->has('status')) {
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
        $voucher = Voucher::with(['group', 'salesPoint', 'transactions'])->findOrFail($id);
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
            $result = $this->voucherService->generateVoucherBatch($request->all());
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

        if ($voucher->status === 'expired') {
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

    public function getTypes()
    {
        $types = VoucherType::orderBy('type_name')->get();
        return response()->json(['types' => $types]);
    }

    public function storeType(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'type_name' => 'required|string|max:100',
            'duration_hours' => 'required|integer|min:1',
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

        $type = VoucherType::create([
            'type_name' => $request->type_name,
            'duration_hours' => $request->duration_hours,
            'base_amount' => $request->base_amount,
            'description' => $request->description,
            'data_limit_mb' => $request->data_limit_mb,
            'speed_limit_kbps' => $request->speed_limit_kbps,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Voucher type created successfully',
            'type' => $type,
        ], 201);
    }

    public function updateType(Request $request, $id)
    {
        $type = VoucherType::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'type_name' => 'sometimes|string|max:100',
            'duration_hours' => 'sometimes|integer|min:1',
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

        $type->update($request->only([
            'type_name', 'duration_hours', 'base_amount', 'description',
            'data_limit_mb', 'speed_limit_kbps', 'is_active'
        ]));

        return response()->json([
            'message' => 'Voucher type updated successfully',
            'type' => $type->fresh(),
        ]);
    }

    public function destroyType($id)
    {
        $type = VoucherType::findOrFail($id);
        $type->delete();

        return response()->json([
            'message' => 'Voucher type deleted successfully',
        ]);
    }

    public function getGroups()
    {
        $groups = VoucherGroup::with('salesPoint')
            ->withCount([
                'vouchers as total_vouchers',
                'vouchers as unused_count' => function ($query) {
                    $query->where('status', 'unused');
                },
                'vouchers as used_count' => function ($query) {
                    $query->where('status', 'used');
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

    public function statistics()
    {
        // Overall statistics
        $stats = [
            'total_vouchers' => Voucher::count(),
            'unused_vouchers' => Voucher::unused()->count(),
            'used_vouchers' => Voucher::used()->count(),
            'expired_vouchers' => Voucher::expired()->count(),
            'total_revenue' => Voucher::used()->sum('price'),
            'vouchers_by_status' => Voucher::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
        ];

        // Daily statistics (last 30 days)
        $stats['daily'] = Voucher::selectRaw('DATE(first_used_at) as date, COUNT(*) as vouchers_used, SUM(price) as revenue, COUNT(DISTINCT used_by_mac) as unique_devices')
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
            $stats['by_sales_point'] = VoucherGroup::join('vouchers', 'voucher_groups.id', '=', 'vouchers.group_id')
                ->join('voucher_sales_points', 'voucher_groups.sales_point_id', '=', 'voucher_sales_points.id')
                ->selectRaw('voucher_sales_points.id, voucher_sales_points.name, COUNT(vouchers.id) as total_vouchers, SUM(CASE WHEN vouchers.status = "used" THEN 1 ELSE 0 END) as used, SUM(CASE WHEN vouchers.status = "used" THEN vouchers.price ELSE 0 END) as revenue')
                ->groupBy('voucher_sales_points.id', 'voucher_sales_points.name')
                ->get()
                ->map(function ($point) {
                    return [
                        'name' => $point->name,
                        'total_vouchers' => (int) $point->total_vouchers,
                        'used' => (int) $point->used,
                        'revenue' => (float) $point->revenue,
                    ];
                });
        } catch (\Exception $e) {
            // If query fails (no data or table issues), return empty array
            $stats['by_sales_point'] = [];
        }

        return response()->json($stats);
    }
}
