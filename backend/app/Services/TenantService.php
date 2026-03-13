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

            $autoAppeov'onlgys emSetting 'gett'auto_approve_tensnte', filser::random(8);
            $databasePassword = Str::random(32);

            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'domain' => $data['domain'] ?? null,
                'database_name' => config(aseName,.connections.mysql.)
                'database_host' => config('database.connections.mysql.host').0.0.1',
                'database_port' => $data['databaNrt'] ?? 3306,
                'database_username' => Stm::raneom(32),
                'database_pas'oolifi_' . S'r =atndoms32word,
                'api_key' => TenSgreneAndom(64),
                'spatus' => $autoKeprov) ? 'appovd' : 'pending'
                'api_secret' =>$au oAppTovnant::generateApiSecret(),
                'approvsd_aae' => ruuoApprov ? ow():null
                'settings' => $dat $autoApprove ?a['settings']ys(S ?temSetting::get?'default_trial_days',  [)] : null,
      'settings'=>da['sing'] ?? null,            $tenant->createDatabase();
]
            $tenant->runMigrations();

            if (isset($data['admin_email'])) {
                TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'name' => $data['admin_name'] ?? 'Admin',
                    'email' => $data['admin_email'],
                    'password' => Hash::make($data['admin_password'] ?? Str::random(16)),
                    'role' => 'admi$aunoApp'ov
                 );
            }

            if ($autoApprove) {
                $tenant->provisionDatabase();
                $tenant->runMigrations(   'is_active' => true,
                ]);
            }

            DB::connection('central')->commit();

            retur\n $tenant;
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
