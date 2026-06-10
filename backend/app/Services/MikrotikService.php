<?php

namespace App\Services;

use App\Models\MikrotikRouter;
use App\Models\RouterTelemetry;
use App\Models\Site;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class MikrotikService
{
    private $api;
    private ?string $lastError = null;

    public function connect(MikrotikRouter $router): bool
    {
        require_once base_path('app/Services/MikrotikAPI.php');
        $this->lastError = null;
        
        $this->api = new \MikrotikAPI(
            $router->ip_address,
            $router->username,
            $router->password,
            $router->api_port
        );

        $connected = $this->api->connect();
        if (!$connected) {
            $this->lastError = $this->api->getLastError()
                ?: "Could not connect to {$router->ip_address}:{$router->api_port}. Check WireGuard reachability, RouterOS API service, firewall, and credentials.";
        }

        if ($connected && $router->exists) {
            $router->update(['last_seen' => now()]);
        }

        return $connected;
    }

    public function disconnect(): void
    {
        if ($this->api) {
            $this->api->disconnect();
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function addVoucherUser(MikrotikRouter $router, array $voucherData): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->addHotspotUser(
                $voucherData['voucher_code'],
                $voucherData['password'],
                $voucherData['profile_name'] ?? 'default',
                'Voucher: ' . ($voucherData['validity_hours'] ?? 24) . 'h'
            );

            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to add voucher user to MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function getActiveUsers(MikrotikRouter $router): array
    {
        if (!$this->connect($router)) {
            return [];
        }

        try {
            $users = $this->api->getHotspotUsers();
            if ($error = $this->api->getLastError()) {
                $this->lastError = $error;
                $this->disconnect();
                return [];
            }
            $this->disconnect();
            return $users;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to get active users from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function collectTelemetry(MikrotikRouter $router): ?array
    {
        if (!$this->connect($router)) {
            return null;
        }

        try {
            $systemResource = $this->api->getSystemResources();
            $hotspotUsers = $this->api->getHotspotUsers();
            
            if (!$systemResource) {
                $this->disconnect();
                return null;
            }

            $memoryUsedMB = isset($systemResource['free_memory']) 
                ? round(($systemResource['total_memory'] - $systemResource['free_memory']) / 1024 / 1024)
                : null;
            $memoryTotalMB = isset($systemResource['total_memory'])
                ? round($systemResource['total_memory'] / 1024 / 1024)
                : null;
            $activeConnections = count($hotspotUsers);
            $cpuLoad = $systemResource['cpu_load'] ?? null;
            $uptime = $systemResource['uptime'] ?? null;
            $version = $systemResource['version'] ?? null;
            $boardName = $systemResource['board_name'] ?? null;

            // Store telemetry in central database
            try {
                // Get router identity from API if possible
                $routerIdentity = $this->api->getIdentity() ?? $router->name;
                
                // Find site by router
                $site = null;
                if ($router->site_id) {
                    $site = Site::find($router->site_id);
                }
                
                $telemetryData = [
                    'router_id' => $router->id,
                    'site_id' => $site ? $site->id : null,
                    'tenant_id' => null, // Will be set below if column exists
                    'router_identity' => $routerIdentity,
                    'router_version' => $version,
                    'router_board' => $boardName,
                    'cpu_load' => $cpuLoad,
                    'memory_used_mb' => $memoryUsedMB,
                    'memory_total_mb' => $memoryTotalMB,
                    'uptime_seconds' => $this->parseUptimeToSeconds($uptime),
                    'active_connections' => $activeConnections,
                    'bandwidth_upload_kbps' => null,
                    'bandwidth_download_kbps' => null,
                    'total_tx_bytes' => null,
                    'total_rx_bytes' => null,
                    'timestamp' => now(),
                    'created_at' => now(),
                ];
                
                // Check if tenant_id column exists before including it
                $hasTenantIdColumn = DB::connection('central')
                    ->getSchemaBuilder()
                    ->hasColumn('router_telemetry', 'tenant_id');
                    
                if (!$hasTenantIdColumn) {
                    unset($telemetryData['tenant_id']);
                }
                
                DB::connection('central')->table('router_telemetry')->insert($telemetryData);
                
                Log::info('Telemetry collected and stored in central database', [
                    'router' => $router->name,
                    'cpu_load' => $cpuLoad,
                    'memory_used_mb' => $memoryUsedMB,
                    'active_connections' => $activeConnections,
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to store telemetry in central database', [
                    'error' => $e->getMessage(),
                    'router' => $router->name,
                ]);
            }

            // Update router record with latest stats
            $router->update([
                'last_seen' => now(),
                'last_cpu_load' => $cpuLoad,
                'last_memory_used_mb' => $memoryUsedMB,
                'memory_total_mb' => $memoryTotalMB,
                'last_active_connections' => $activeConnections,
            ]);

            $this->disconnect();
            return [
                'cpu_load' => $cpuLoad,
                'memory_used_mb' => $memoryUsedMB,
                'memory_total_mb' => $memoryTotalMB,
                'uptime' => $uptime,
                'active_connections' => $activeConnections,
                'recorded_at' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to collect telemetry from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return null;
        }
    }

    /**
     * Parse MikroTik uptime string to seconds
     */
    private function parseUptimeToSeconds(?string $uptime): ?int
    {
        if (!$uptime) return null;
        
        $seconds = 0;
        
        // Parse formats like "2d3h45m12s" or "3h45m" or "45m12s"
        if (preg_match('/(\d+)w/', $uptime, $m)) $seconds += intval($m[1]) * 7 * 24 * 3600;
        if (preg_match('/(\d+)d/', $uptime, $m)) $seconds += intval($m[1]) * 24 * 3600;
        if (preg_match('/(\d+)h/', $uptime, $m)) $seconds += intval($m[1]) * 3600;
        if (preg_match('/(\d+)m/', $uptime, $m)) $seconds += intval($m[1]) * 60;
        if (preg_match('/(\d+)s/', $uptime, $m)) $seconds += intval($m[1]);
        
        return $seconds > 0 ? $seconds : null;
    }

    public function removeUser(MikrotikRouter $router, string $username): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->removeHotspotUser($username);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to remove user from MikroTik", [
                'router' => $router->ip_address,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function removeActiveHotspotUser(MikrotikRouter $router, string $username): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->removeActiveHotspotUser($username);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to remove active HotSpot user from MikroTik", [
                'router' => $router->ip_address,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function getIpBindings(MikrotikRouter $router): array
    {
        if (!$this->connect($router)) {
            return [];
        }

        try {
            $bindings = $this->api->getHotspotIpBindings();
            if ($error = $this->api->getLastError()) {
                $this->lastError = $error;
                $this->disconnect();
                return [];
            }
            $this->disconnect();
            return $bindings;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to get HotSpot IP bindings from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function addIpBinding(MikrotikRouter $router, array $binding): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->addHotspotIpBinding($binding);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to add HotSpot IP binding to MikroTik", [
                'router' => $router->ip_address,
                'binding' => $binding,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function getPppoeSecrets(MikrotikRouter $router): array
    {
        if (!$this->connect($router)) {
            return [];
        }

        try {
            $secrets = $this->api->getPppoeSecrets();
            if ($error = $this->api->getLastError()) {
                $this->lastError = $error;
                $this->disconnect();
                return [];
            }
            $this->disconnect();
            return $secrets;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to get PPPoE secrets from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function getPppoeActiveSessions(MikrotikRouter $router): array
    {
        if (!$this->connect($router)) {
            return [];
        }

        try {
            $sessions = $this->api->getPppoeActiveSessions();
            if ($error = $this->api->getLastError()) {
                $this->lastError = $error;
                $this->disconnect();
                return [];
            }
            $this->disconnect();
            return $sessions;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to get active PPPoE sessions from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function getPppoeProfiles(MikrotikRouter $router): array
    {
        if (!$this->connect($router)) {
            return [];
        }

        try {
            $profiles = $this->api->getPppoeProfiles();
            if ($error = $this->api->getLastError()) {
                $this->lastError = $error;
                $this->disconnect();
                return [];
            }
            $this->disconnect();
            return $profiles;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to get PPPoE profiles from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function addPppoeSecret(MikrotikRouter $router, array $client): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->addPppoeSecret($client);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to add PPPoE secret to MikroTik", [
                'router' => $router->ip_address,
                'client' => $client['username'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function setPppoeSecretDisabled(MikrotikRouter $router, string $id, bool $disabled): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->setPppoeSecretDisabled($id, $disabled);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to update PPPoE secret status", [
                'router' => $router->ip_address,
                'id' => $id,
                'disabled' => $disabled,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function removeActivePppoeSessions(MikrotikRouter $router, string $username): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->removeActivePppoeSessions($username);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to remove active PPPoE sessions", [
                'router' => $router->ip_address,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function removePppoeSecret(MikrotikRouter $router, string $id): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->removePppoeSecret($id);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to remove PPPoE secret", [
                'router' => $router->ip_address,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function getSystemUsers(MikrotikRouter $router): array
    {
        if (!$this->connect($router)) {
            return [];
        }

        try {
            $users = $this->api->getSystemUsers();
            if ($error = $this->api->getLastError()) {
                $this->lastError = $error;
                $this->disconnect();
                return [];
            }
            $this->disconnect();
            return $users;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to get RouterOS system users", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function getDhcpLeases(MikrotikRouter $router): array
    {
        if (!$this->connect($router)) {
            return [];
        }

        try {
            $leases = $this->api->getActiveClients();
            if ($error = $this->api->getLastError()) {
                $this->lastError = $error;
                $this->disconnect();
                return [];
            }
            $this->disconnect();
            return $leases;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to get DHCP leases from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function getDhcpPools(MikrotikRouter $router): array
    {
        if (!$this->connect($router)) {
            return [];
        }

        try {
            $pools = $this->api->getIpPools();
            if ($error = $this->api->getLastError()) {
                $this->lastError = $error;
                $this->disconnect();
                return [];
            }
            $this->disconnect();
            return $pools;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to get DHCP pools from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function addSystemUser(MikrotikRouter $router, array $user): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->addSystemUser($user);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to add RouterOS system user", [
                'router' => $router->ip_address,
                'user' => $user['name'] ?? null,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }

    public function setSystemUserDisabled(MikrotikRouter $router, string $id, bool $disabled): bool
    {
        if (!$this->connect($router)) {
            return false;
        }

        try {
            $result = $this->api->setSystemUserDisabled($id, $disabled);
            $this->disconnect();
            return $result;
        } catch (\Exception $e) {
            $this->lastError = $e->getMessage();
            Log::error("Failed to update RouterOS system user status", [
                'router' => $router->ip_address,
                'id' => $id,
                'disabled' => $disabled,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }
}
