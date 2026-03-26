<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $tenant = app('tenant');
        
        // Get limit from request, default to 100
        $limit = $request->input('limit', 100);
        
        try {
            // Query clients from tenant database
            // This assumes you have a clients or hotspot_users table
            $clients = DB::connection('tenant')
                ->table('hotspot_users')
                ->select([
                    'id',
                    'mac_address',
                    'ip_address',
                    'username',
                    'device_type',
                    'uptime_seconds',
                    'data_uploaded_mb',
                    'data_downloaded_mb',
                    DB::raw('(data_uploaded_mb + data_downloaded_mb) as total_data_mb'),
                    'signal_strength',
                    'last_seen',
                    'router_name',
                    'voucher_code',
                    'profile_name',
                    'expires_at',
                    DB::raw('CASE WHEN last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN "online" ELSE "offline" END as status'),
                    DB::raw('COALESCE((SELECT SUM(amount) FROM transactions WHERE transactions.msisdn = hotspot_users.username), 0) as total_spent'),
                    DB::raw('(SELECT COUNT(*) FROM sessions WHERE sessions.mac_address = hotspot_users.mac_address) as total_sessions'),
                ])
                ->orderBy('last_seen', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'clients' => $clients,
                'total' => $clients->count(),
            ]);
        } catch (\Exception $e) {
            // If table doesn't exist or query fails, return empty array
            return response()->json([
                'clients' => [],
                'total' => 0,
                'message' => 'No client data available',
            ]);
        }
    }

    public function show($id)
    {
        $tenant = app('tenant');
        
        try {
            $client = DB::connection('tenant')
                ->table('hotspot_users')
                ->where('id', $id)
                ->first();

            if (!$client) {
                return response()->json([
                    'error' => 'Client not found',
                ], 404);
            }

            return response()->json($client);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch client',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function refresh(Request $request)
    {
        // This would trigger a refresh from MikroTik routers
        // For now, just return the current data
        return $this->index($request);
    }
}
