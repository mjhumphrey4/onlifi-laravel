<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TelemetryController extends Controller
{
    public function receive(Request $request)
    {
        // Log the incoming request for debugging
        Log::info('Telemetry received', [
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);

        // Extract Bearer token from Authorization header
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('Telemetry rejected: Missing or invalid Authorization header', [
                'headers' => $request->headers->all()
            ]);
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing or invalid Authorization header',
            ], 401);
        }

        $token = substr($authHeader, 7); // Remove "Bearer " prefix

        // Find site by API token
        $site = Site::where('api_token', $token)->first();
        if (!$site) {
            Log::warning('Telemetry rejected: Invalid API token', [
                'token_prefix' => substr($token, 0, 10) . '...',
                'all_sites' => Site::pluck('name', 'id')->toArray()
            ]);
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API token',
            ], 401);
        }

        Log::info('Telemetry authenticated successfully', [
            'site' => $site->name,
            'site_id' => $site->id,
        ]);

        // Store telemetry data directly without router dependency
        try {
            // Check if router_telemetry table exists
            if (!DB::getSchemaBuilder()->hasTable('router_telemetry')) {
                Log::error('router_telemetry table does not exist');
                return response()->json([
                    'error' => 'Configuration error',
                    'message' => 'Telemetry storage not configured',
                ], 500);
            }

            $telemetryData = [
                'router_id' => null, // Will be null for now
                'site_id' => $site->id,
                'router_identity' => $request->input('router_identity', $request->input('router_name', 'unknown')),
                'router_version' => $request->input('router_version'),
                'router_board' => $request->input('router_board'),
                'cpu_load' => $request->input('cpu_load', 0),
                'memory_total_mb' => $request->input('memory_total_mb', 0),
                'memory_used_mb' => $request->input('memory_used_mb', 0),
                'uptime_seconds' => $request->input('uptime_seconds', 0),
                'active_connections' => $request->input('active_connections', 0),
                'bandwidth_download_kbps' => $request->input('bandwidth_download_kbps', 0),
                'bandwidth_upload_kbps' => $request->input('bandwidth_upload_kbps', 0),
                'total_tx_bytes' => $request->input('total_tx_bytes', 0),
                'total_rx_bytes' => $request->input('total_rx_bytes', 0),
                'timestamp' => $request->input('timestamp', now()),
                'created_at' => now(),
            ];

            DB::table('router_telemetry')->insert($telemetryData);

            Log::info('Telemetry stored successfully', [
                'site' => $site->name,
                'router_identity' => $telemetryData['router_identity'],
                'cpu_load' => $telemetryData['cpu_load'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Telemetry data received successfully',
                'site' => $site->name,
                'router' => $telemetryData['router_identity'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to store telemetry', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Internal server error',
                'message' => 'Failed to store telemetry data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
