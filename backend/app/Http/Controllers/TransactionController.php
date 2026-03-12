<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $query = Transaction::with('voucher');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('origin_site')) {
            $query->where('origin_site', $request->origin_site);
        }

        if ($request->has('msisdn')) {
            $query->where('msisdn', 'LIKE', '%' . $request->msisdn . '%');
        }

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json($transactions);
    }

    public function show($id)
    {
        $transaction = Transaction::with('voucher')->findOrFail($id);
        return response()->json($transaction);
    }

    public function statistics(Request $request)
    {
        $query = Transaction::query();

        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $stats = [
            'total_transactions' => $query->count(),
            'successful_transactions' => (clone $query)->successful()->count(),
            'pending_transactions' => (clone $query)->pending()->count(),
            'failed_transactions' => (clone $query)->failed()->count(),
            'total_revenue' => (clone $query)->successful()->sum('amount'),
            'average_transaction_value' => (clone $query)->successful()->avg('amount'),
            'transactions_by_status' => Transaction::selectRaw('status, COUNT(*) as count, SUM(amount) as total')
                ->groupBy('status')
                ->get(),
            'transactions_by_origin' => Transaction::selectRaw('origin_site, COUNT(*) as count, SUM(amount) as total')
                ->whereNotNull('origin_site')
                ->groupBy('origin_site')
                ->get(),
        ];

        return response()->json($stats);
    }

    public function dailyReport(Request $request)
    {
        $days = $request->days ?? 30;

        $report = Transaction::selectRaw('DATE(created_at) as date, status, COUNT(*) as count, SUM(amount) as total')
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date', 'status')
            ->orderBy('date', 'desc')
            ->get();

        return response()->json($report);
    }
}
