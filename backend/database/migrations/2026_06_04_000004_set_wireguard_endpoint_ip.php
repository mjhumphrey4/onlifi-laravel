<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('system_settings')) {
            $settings = [
                ['wireguard_endpoint_host', '89.167.42.53', 'string', 'router', 'WireGuard server host or IP used by router provisioning'],
                ['wireguard_endpoint_port', '51820', 'integer', 'router', 'WireGuard server UDP port'],
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

        if (Schema::connection('central')->hasTable('sites')) {
            if (Schema::connection('central')->hasColumn('sites', 'vpn_public_host')) {
                DB::connection('central')
                    ->table('sites')
                    ->where(function ($query) {
                        $query->whereNull('vpn_public_host')
                            ->orWhere('vpn_public_host', '')
                            ->orWhere('vpn_public_host', 'vpn.onlifi.net');
                    })
                    ->update([
                        'vpn_public_host' => '89.167.42.53',
                        'updated_at' => now(),
                    ]);
            }

            if (Schema::connection('central')->hasColumn('sites', 'vpn_public_port')) {
                DB::connection('central')
                    ->table('sites')
                    ->where(function ($query) {
                        $query->whereNull('vpn_public_port')
                            ->orWhere('vpn_public_port', 8443);
                    })
                    ->update([
                        'vpn_public_port' => 51820,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
