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
            $result = $this->api->addUser(
                $voucherData['voucher_code'],
                $voucherData['password'],
                $voucherData['profile_name'] ?? 'default',
                $voucherData['validity_hours'] ?? 24
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
            $users = $this->api->getActiveUsers();
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
            $systemResource = $this->api->getSystemResource();
            $interfaces = $this->api->getInterfaces();

            $telemetry = RouterTelemetry::create([
                'router_id' => $router->id,
                'cpu_load' => $systemResource['cpu_load'] ?? null,
                'memory_used_mb' => isset($systemResource['free_memory']) 
                    ? ($systemResource['total_memory'] - $systemResource['free_memory']) / 1024 / 1024
                    : null,
                'memory_total_mb' => isset($systemResource['total_memory'])
                    ? $systemResource['total_memory'] / 1024 / 1024
                    : null,
                'uptime_seconds' => $systemResource['uptime'] ?? null,
                'active_connections' => $systemResource['active_connections'] ?? null,
                'total_clients' => count($this->getActiveUsers($router)),
                'recorded_at' => now(),
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
            $result = $this->api->removeUser($username);
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
