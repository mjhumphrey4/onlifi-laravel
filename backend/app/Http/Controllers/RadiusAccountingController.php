<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RadiusAccountingController extends Controller
{
    /**
     * Get active hotspot users from radacct table
     * Users are considered active if they have a session start but no stop time
     */
    public function getActiveUsers(Request $request)
    {
        try {
            // Query active sessions from radacct table in tenant database
            $activeUsers = DB::connection('tenant')->table('radacct')
                ->select([
                    'username',
                    'nasipaddress',
                    'framedipaddress',
                    'callingstationid as mac_address',
                    'calledstationid as hotspot_name',
                    'acctstarttime as connected_at',
                    'acctsessiontime as session_duration',
                    'acctinputoctets as bytes_downloaded',
                    'acctoutputoctets as bytes_uploaded',
                    'acctsessionid as session_id',
                    DB::raw('(acctinputoctets + acctoutputoctets) as total_bytes')
                ])
                ->whereNotNull('acctstarttime')
                ->whereNull('acctstoptime')
                ->orderBy('acctstarttime', 'desc')
                ->get();

            // Format the response with additional computed fields
            $formattedUsers = $activeUsers->map(function($user) {
                $connectedAt = $user->connected_at ? \Carbon\Carbon::parse($user->connected_at) : null;
                $sessionDuration = $user->session_duration ?? 0;
                
                return [
                    'username' => $user->username,
                    'mac_address' => $user->mac_address ?? 'Unknown',
                    'ip_address' => $user->framedipaddress ?? 'Unknown',
                    'hotspot_name' => $user->hotspot_name ?? 'Unknown',
                    'nas_ip' => $user->nasipaddress ?? 'Unknown',
                    'connected_at' => $connectedAt ? $connectedAt->toIso8601String() : null,
                    'connected_since' => $connectedAt ? $connectedAt->diffForHumans() : 'Unknown',
                    'session_duration_seconds' => $sessionDuration,
                    'session_duration_formatted' => $this->formatDuration($sessionDuration),
                    'bytes_downloaded' => $user->bytes_downloaded ?? 0,
                    'bytes_uploaded' => $user->bytes_uploaded ?? 0,
                    'total_bytes' => $user->total_bytes ?? 0,
                    'data_usage_formatted' => $this->formatBytes($user->total_bytes ?? 0),
                    'session_id' => $user->session_id,
                ];
            });

            return response()->json([
                'success' => true,
                'total_active_users' => $formattedUsers->count(),
                'users' => $formattedUsers,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get active users from radacct', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'total_active_users' => 0,
                'users' => [],
                'error' => $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    /**
     * Get accounting history for a specific user
     */
    public function getUserHistory(Request $request, $username)
    {
        try {
            $sessions = DB::connection('tenant')->table('radacct')
                ->select([
                    'acctsessionid',
                    'acctstarttime',
                    'acctstoptime',
                    'acctsessiontime',
                    'acctinputoctets',
                    'acctoutputoctets',
                    'acctterminatecause',
                    'nasipaddress',
                    'framedipaddress',
                    'callingstationid',
                ])
                ->where('username', $username)
                ->orderBy('acctstarttime', 'desc')
                ->limit(50)
                ->get();

            $formattedSessions = $sessions->map(function($session) {
                return [
                    'session_id' => $session->acctsessionid,
                    'start_time' => $session->acctstarttime,
                    'stop_time' => $session->acctstoptime,
                    'duration_seconds' => $session->acctsessiontime ?? 0,
                    'duration_formatted' => $this->formatDuration($session->acctsessiontime ?? 0),
                    'bytes_downloaded' => $session->acctinputoctets ?? 0,
                    'bytes_uploaded' => $session->acctoutputoctets ?? 0,
                    'total_bytes' => ($session->acctinputoctets ?? 0) + ($session->acctoutputoctets ?? 0),
                    'data_usage_formatted' => $this->formatBytes(($session->acctinputoctets ?? 0) + ($session->acctoutputoctets ?? 0)),
                    'terminate_cause' => $session->acctterminatecause ?? 'Unknown',
                    'nas_ip' => $session->nasipaddress,
                    'user_ip' => $session->framedipaddress,
                    'mac_address' => $session->callingstationid,
                    'is_active' => is_null($session->acctstoptime),
                ];
            });

            return response()->json([
                'success' => true,
                'username' => $username,
                'total_sessions' => $formattedSessions->count(),
                'sessions' => $formattedSessions,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get user history', [
                'username' => $username,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get accounting statistics
     */
    public function getStats(Request $request)
    {
        try {
            $stats = [
                'active_sessions' => DB::connection('tenant')->table('radacct')
                    ->whereNotNull('acctstarttime')
                    ->whereNull('acctstoptime')
                    ->count(),
                
                'total_sessions_today' => DB::connection('tenant')->table('radacct')
                    ->whereDate('acctstarttime', today())
                    ->count(),
                
                'total_data_today_bytes' => DB::connection('tenant')->table('radacct')
                    ->whereDate('acctstarttime', today())
                    ->sum(DB::raw('acctinputoctets + acctoutputoctets')),
                
                'avg_session_duration_today' => DB::connection('tenant')->table('radacct')
                    ->whereDate('acctstarttime', today())
                    ->whereNotNull('acctstoptime')
                    ->avg('acctsessiontime'),
            ];

            $stats['total_data_today_formatted'] = $this->formatBytes($stats['total_data_today_bytes'] ?? 0);
            $stats['avg_session_duration_formatted'] = $this->formatDuration($stats['avg_session_duration_today'] ?? 0);

            return response()->json([
                'success' => true,
                'stats' => $stats,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get accounting stats', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format duration in seconds to human-readable format
     */
    private function formatDuration($seconds)
    {
        if (!$seconds || $seconds < 0) {
            return '0s';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($minutes > 0) $parts[] = "{$minutes}m";
        if ($secs > 0 || empty($parts)) $parts[] = "{$secs}s";

        return implode(' ', $parts);
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes($bytes)
    {
        if (!$bytes || $bytes < 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        $value = $bytes / pow(1024, $power);
        return round($value, 2) . ' ' . $units[$power];
    }
}
