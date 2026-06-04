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
            ['wireguard_endpoint_host', '89.167.42.53', 'string', 'router', 'WireGuard server host or IP used by router provisioning'],
            ['wireguard_endpoint_port', '51820', 'integer', 'router', 'WireGuard server UDP port'],
            ['wireguard_server_public_key', '', 'string', 'router', 'WireGuard server public key used in router peers'],
            ['wireguard_allowed_address', '10.10.1.0/24', 'string', 'router', 'WireGuard routes allowed through the server peer'],
            ['wireguard_client_dns', '', 'string', 'router', 'Optional DNS server included in generated WireGuard configs'],
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
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('system_settings')) {
            return;
        }

        DB::connection('central')
            ->table('system_settings')
            ->whereIn('key', [
                'wireguard_endpoint_host',
                'wireguard_endpoint_port',
                'wireguard_server_public_key',
                'wireguard_allowed_address',
                'wireguard_client_dns',
            ])
            ->delete();
    }
};
