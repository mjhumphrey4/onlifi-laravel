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
            ['router_default_lan_cidr', '192.168.15.1/19', 'string', 'router', 'Default router LAN gateway/CIDR for provisioned hotspot clients'],
            ['router_default_dhcp_pool', '192.168.16.1-192.168.31.254', 'string', 'router', 'Default DHCP pool, reserving 192.168.15.1-192.168.15.254 for router/admin devices'],
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
