<?php

namespace App\Services;

use App\Models\MikrotikRouter;
use App\Models\RouterTelemetry;
use Illuminate\Support\Facades\Log;

class MikrotikService
{
    private $api;

    public function connect(MikrotikRouter $router): bool
    {
        require_once base_path('app/Services/MikrotikAPI.php');
        
        $this->api = new \MikrotikAPI(
            $router->ip_address,
            $router->username,
            $router->password,
            $router->api_port
        );

        $connected = $this->api->connect();

        if ($connected) {
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
            $this->disconnect();
            return $users;
        } catch (\Exception $e) {
            Log::error("Failed to get active users from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return [];
        }
    }

    public function collectTelemetry(MikrotikRouter $router): ?RouterTelemetry
    {
        if (!$this->connect($router)) {
            return null;
        }

        try {
            $systemResource = $this->api->getSystemResources();
            
            if (!$systemResource) {
                $this->disconnect();
                return null;
            }

            $telemetry = RouterTelemetry::create([
                'router_id' => $router->id,
                'cpu_load' => $systemResource['cpu_load'] ?? null,
                'memory_usage' => isset($systemResource['free_memory']) 
                    ? round(($systemResource['total_memory'] - $systemResource['free_memory']) / 1024 / 1024)
                    : null,
                'uptime' => $systemResource['uptime'] ?? null,
                'active_users' => count($this->api->getHotspotUsers()),
                'recorded_at' => now(),
            ]);

            $router->update([
                'last_cpu_load' => $systemResource['cpu_load'] ?? null,
                'last_memory_used_mb' => isset($systemResource['free_memory']) 
                    ? round(($systemResource['total_memory'] - $systemResource['free_memory']) / 1024 / 1024)
                    : null,
                'memory_total_mb' => isset($systemResource['total_memory'])
                    ? round($systemResource['total_memory'] / 1024 / 1024)
                    : null,
                'last_active_connections' => count($this->api->getHotspotUsers()),
            ]);

            $this->disconnect();
            return $telemetry;
        } catch (\Exception $e) {
            Log::error("Failed to collect telemetry from MikroTik", [
                'router' => $router->ip_address,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return null;
        }
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
            Log::error("Failed to remove user from MikroTik", [
                'router' => $router->ip_address,
                'username' => $username,
                'error' => $e->getMessage(),
            ]);
            $this->disconnect();
            return false;
        }
    }
}
