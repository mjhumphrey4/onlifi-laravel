<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Exception;

class TenantService
{
    public function createTenant(array $data): Tenant
    {
        DB::connection('central')->beginTransaction();

        try {
            $slug = Str::slug($data['name']);
            $autoApprove = SystemSetting::get('auto_approve_tenants', false);
            $databasePassword = Str::random(32);

            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'domain' => $data['domain'] ?? null,
                'database_name' => 'onlifi_' . Str::random(8),
                'database_host' => config('database.connections.mysql.host', '127.0.0.1'),
                'database_port' => $data['database_port'] ?? 3306,
                'database_username' => 'onlifi_' . Str::random(8),
                'database_password' => $databasePassword,
                'api_key' => Tenant::generateApiKey(),
                'api_secret' => Tenant::generateApiSecret(),
                'status' => $autoApprove ? 'approved' : 'pending',
                'approved_at' => $autoApprove ? now() : null,
                'trial_ends_at' => $autoApprove ? now()->addDays(SystemSetting::get('default_trial_days', 30)) : null,
                'settings' => $data['settings'] ?? null,
            ]);

            if (isset($data['admin_email'])) {
                TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['admin_name'] ?? 'Admin',
                    'email' => $data['admin_email'],
                    'password' => Hash::make($data['admin_password'] ?? Str::random(16)),
                    'role' => 'admin',
                    'is_active' => true,
                ]);
            }

            if ($autoApprove) {
                $tenant->provisionDatabase();
                $tenant->runMigrations();
            }

            DB::connection('central')->commit();

            return $tenant;
        } catch (Exception $e) {
            DB::connection('central')->rollBack();
            throw $e;
        }
    }

    public function deleteTenant(Tenant $tenant): bool
    {
        DB::connection('central')->beginTransaction();

        try {
            $connection = DB::connection('mysql');
            $connection->statement("DROP DATABASE IF EXISTS `{$tenant->database_name}`");
            $connection->statement("DROP USER IF EXISTS '{$tenant->database_username}'@'localhost'");

            $tenant->delete();

            DB::connection('central')->commit();

            return true;
        } catch (Exception $e) {
            DB::connection('central')->rollBack();
            throw $e;
        }
    }

    public function suspendTenant(Tenant $tenant): bool
    {
        return $tenant->update(['is_active' => false]);
    }

    public function activateTenant(Tenant $tenant): bool
    {
        return $tenant->update(['is_active' => true]);
    }

    public function extendTrial(Tenant $tenant, int $days): bool
    {
        $newTrialEnd = $tenant->trial_ends_at 
            ? $tenant->trial_ends_at->addDays($days)
            : now()->addDays($days);

        return $tenant->update(['trial_ends_at' => $newTrialEnd]);
    }

    public function subscribe(Tenant $tenant): bool
    {
        return $tenant->update([
            'subscribed_at' => now(),
            'trial_ends_at' => null,
        ]);
    }

    public function regenerateApiCredentials(Tenant $tenant): array
    {
        $apiKey = Tenant::generateApiKey();
        $apiSecret = Tenant::generateApiSecret();

        $tenant->update([
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ]);

        return [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ];
    }

    public function updateSettings(Tenant $tenant, array $settings): bool
    {
        $currentSettings = $tenant->settings ?? [];
        $newSettings = array_merge($currentSettings, $settings);

        return $tenant->update(['settings' => $newSettings]);
    }

    public function getTenantStats(Tenant $tenant): array
    {
        $tenant->configure();

        return [
            'total_transactions' => DB::connection('tenant')->table('transactions')->count(),
            'successful_transactions' => DB::connection('tenant')->table('transactions')->where('status', 'success')->count(),
            'total_vouchers' => DB::connection('tenant')->table('vouchers')->count(),
            'active_vouchers' => DB::connection('tenant')->table('vouchers')->where('status', 'active')->count(),
            'total_routers' => DB::connection('tenant')->table('mikrotik_routers')->count(),
            'active_routers' => DB::connection('tenant')->table('mikrotik_routers')->where('status', 'active')->count(),
        ];
    }
}
