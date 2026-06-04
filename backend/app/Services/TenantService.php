<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\SystemSetting;
use App\Models\Site;
use App\Support\SiteScope;
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
            $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
            $settings['mobile_money_provider'] = $data['mobile_money_provider'] ?? ($settings['mobile_money_provider'] ?? 'yo');
            $settings['router_types'] = array_values(array_unique($data['router_types'] ?? ($settings['router_types'] ?? ['mikrotik'])));
            $settings['signup_site_name'] = $data['site_name'] ?? ($settings['signup_site_name'] ?? $data['name']);
            $smsEnabled = (bool) ($data['sms_enabled'] ?? ($settings['sms_enabled'] ?? false));
            $settings['sms_enabled'] = $smsEnabled;
            $settings['sms_charge_per_sms'] = 35;

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
                'is_active' => $autoApprove,
                'approved_at' => $autoApprove ? now() : null,
                'trial_ends_at' => null,
                'subscription_ends_at' => null,
                'sms_enabled' => $smsEnabled,
                'settings' => $settings,
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

            $this->ensureDefaultSite($tenant->fresh(), $data['site_name'] ?? $data['name'], $this->defaultSiteType($settings['router_types']));

            DB::connection('central')->commit();

            return $tenant;
        } catch (Exception $e) {
            DB::connection('central')->rollBack();
            throw $e;
        }
    }

    public function ensureDefaultSite(Tenant $tenant, ?string $siteName = null, ?string $siteType = null): Site
    {
        SiteScope::ensureCentralSitesTable();

        $name = trim((string) $siteName) ?: $tenant->name;
        $existing = Site::where('tenant_id', $tenant->id)->orderBy('id')->first();
        if ($existing) {
            if (!$existing->database_name && $this->tenantReadyForSiteDatabase($tenant)) {
                $existing->provisionDatabase($tenant);
                return $existing->fresh();
            }

            return $existing;
        }

        $slug = Site::uniqueSlug($name ?: "site-{$tenant->id}");

        $site = Site::create([
            'tenant_id' => $tenant->id,
            'slug' => $slug,
            'name' => $name,
            'description' => 'Default site created during signup.',
            'site_type' => $siteType ?: $this->defaultSiteType($tenant->settings['router_types'] ?? ['mikrotik']),
            'is_active' => true,
            'api_token' => Str::random(64),
            'vpn_username' => $slug,
            'vpn_password' => Str::random(24),
            'vpn_public_host' => 'vpn.onlifi.net',
            'vpn_public_port' => Site::defaultVpnPublicPort(),
            'vpn_status' => 'active',
        ]);

        if ($this->tenantReadyForSiteDatabase($tenant)) {
            $site->provisionDatabase($tenant);
            $site = $site->fresh();
        }

        return $site;
    }

    private function tenantReadyForSiteDatabase(Tenant $tenant): bool
    {
        return (bool) $tenant->database_name && ($tenant->status === 'approved' || $tenant->is_active);
    }

    private function defaultSiteType(array $routerTypes): string
    {
        return in_array('mikrotik', $routerTypes, true) ? 'mikrotik' : 'omada';
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
            'active_vouchers' => DB::connection('tenant')->table('vouchers')->whereIn('status', ['reserved', 'in_use'])->count(),
            'total_routers' => DB::connection('tenant')->table('mikrotik_routers')->count(),
            'active_routers' => DB::connection('tenant')->table('mikrotik_routers')->where('is_active', true)->count(),
        ];
    }
}
