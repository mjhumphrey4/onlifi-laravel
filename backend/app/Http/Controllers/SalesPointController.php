<?php

namespace App\Http\Controllers;

use App\Models\VoucherSalesPoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SalesPointController extends Controller
{
    public function index()
    {
        $salesPoints = VoucherSalesPoint::withCount(['vouchers as total_vouchers'])
            ->withSum('vouchers as total_revenue', 'price')
            ->orderBy('name')
            ->get();

        return response()->json($salesPoints);
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

        $salesPoint = VoucherSalesPoint::create([
            'name' => $request->name,
            'location' => $request->location,
            'contact_person' => $request->contact_person,
            'contact_phone' => $request->contact_phone,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Sales point created successfully',
            'sales_point' => $salesPoint,
        ], 201);
    }

    public function show($id)
    {
        $salesPoint = VoucherSalesPoint::withCount(['vouchers as total_vouchers'])
            ->withSum('vouchers as total_revenue', 'price')
            ->findOrFail($id);

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
