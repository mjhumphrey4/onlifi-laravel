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
        $validator = Validator::make($request->all(), [
            'group_name' => 'required|string|max:100',
            'profile_name' => 'required|string|max:64',
            'validity_hours' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'count' => 'required|integer|min:1|max:1000',
            'description' => 'nullable|string',
            'data_limit_mb' => 'nullable|integer',
            'speed_limit_kbps' => 'nullable|integer',
            'sales_point_id' => 'nullable|exists:voucher_sales_points,id',
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
        $types = VoucherType::active()->get();
        return response()->json($types);
    }

    public function getGroups()
    {
        $groups = VoucherGroup::with('salesPoint')
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($groups);
    }

    public function statistics()
    {
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

        return response()->json($stats);
    }
}
