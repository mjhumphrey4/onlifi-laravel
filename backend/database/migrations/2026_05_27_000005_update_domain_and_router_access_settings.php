<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('system_settings')) {
            return;
        }

        $settings = [
            ['api_base_url', 'https://api.onlifi.net', 'string', 'domains', 'Public API base URL used by routers and generated hotspot files'],
            ['dashboard_url', 'https://onlifi.net', 'string', 'domains', 'Public dashboard URL used in emails and frontend links'],
            ['manual_payment_base_url', 'https://pay.onlifi.net', 'string', 'domains', 'Base URL for manually managed captive payment folders'],
            ['router_admin_username', 'onlifi', 'string', 'router', 'Dedicated RouterOS administrator username for remote telemetry access'],
            ['router_admin_password', 'onlifi-router-admin-change-me', 'string', 'router', 'Dedicated RouterOS administrator password for remote telemetry access'],
            ['router_remote_vpn_cidr', '10.10.1.0/24', 'string', 'router', 'SSTP VPN subnet allowed to access router API'],
        ];

        foreach ($settings as [$key, $value, $type, $group, $description]) {
            DB::connection('central')->table('system_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'value' => $value,
                    'type' => $type,
                    'group' => $group,
                    'description' => $description,
                    'is_public' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        //
    }
};
