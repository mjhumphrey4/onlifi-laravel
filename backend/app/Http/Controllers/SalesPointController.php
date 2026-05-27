<?php

namespace App\Http\Controllers;

use App\Models\VoucherSalesPoint;
use App\Support\SiteScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SalesPointController extends Controller
{
    public function index(Request $request)
    {
        try {
            $site = SiteScope::selectedSite($request);
            $query = VoucherSalesPoint::query();

            if (Schema::connection('tenant')->hasColumn('voucher_sales_points', 'tenant_id') && app()->bound('tenant')) {
                $query->where(function ($q) {
                    $q->where('tenant_id', app('tenant')->id)->orWhereNull('tenant_id');
                });
            }

            SiteScope::applyToTenantTable($query, 'voucher_sales_points', $site);

            $salesPoints = $query->orderBy('name')->get();

            return response()->json([
                'sales_points' => $salesPoints,
                'data' => $salesPoints,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to load sales points', ['error' => $e->getMessage()]);
            return response()->json([
                'sales_points' => [],
                'data' => [],
                'error' => $e->getMessage()
            ]);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'location' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:100',
            'contact_phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $site = SiteScope::selectedSite($request);

        $data = [
            'name' => $request->name,
            'location' => $request->location,
            'contact_person' => $request->contact_person,
            'contact_phone' => $request->contact_phone,
            'is_active' => true,
        ];

        $data = SiteScope::tenantCompatColumns('voucher_sales_points', $data);
        $data = SiteScope::withSiteColumn('voucher_sales_points', $data, $site);

        $salesPoint = VoucherSalesPoint::create($data);

        return response()->json([
            'message' => 'Site created successfully',
            'site' => $salesPoint,
            'sales_point' => $salesPoint,
        ], 201);
    }

    public function show($id)
    {
        $salesPoint = VoucherSalesPoint::findOrFail($id);

        return response()->json($salesPoint);
    }

    public function update(Request $request, $id)
    {
        $salesPoint = VoucherSalesPoint::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:100',
            'location' => 'nullable|string|max:255',
            'contact_person' => 'nullable|string|max:100',
            'contact_phone' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $salesPoint->update($request->only([
            'name', 'location', 'contact_person', 'contact_phone', 'is_active'
        ]));

        return response()->json([
            'message' => 'Sales point updated successfully',
            'sales_point' => $salesPoint->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $salesPoint = VoucherSalesPoint::findOrFail($id);
        
        $voucherCount = $salesPoint->vouchers()->count();
        if ($voucherCount > 0) {
            return response()->json([
                'error' => 'Cannot delete sales point with existing vouchers',
                'message' => "This sales point has {$voucherCount} vouchers associated with it",
            ], 400);
        }

        $salesPoint->delete();

        return response()->json([
            'message' => 'Sales point deleted successfully',
        ]);
    }
}
