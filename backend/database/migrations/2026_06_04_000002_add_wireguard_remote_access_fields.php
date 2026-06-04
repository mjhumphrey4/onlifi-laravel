<?php

use App\Models\Site;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('sites', 'wireguard_private_key')) {
                $table->string('wireguard_private_key')->nullable()->after('vpn_last_seen_at');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'wireguard_public_key')) {
                $table->string('wireguard_public_key')->nullable()->after('wireguard_private_key');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'wireguard_preshared_key')) {
                $table->string('wireguard_preshared_key')->nullable()->after('wireguard_public_key');
            }
        });

        DB::connection('central')->table('sites')->orderBy('id')->get()->each(function ($site) {
            $updates = [];

            if (empty($site->wireguard_private_key) || empty($site->wireguard_public_key)) {
                $keys = Site::generateWireGuardKeyPair();
                $updates['wireguard_private_key'] = $site->wireguard_private_key ?: $keys['private_key'];
                $updates['wireguard_public_key'] = $site->wireguard_public_key ?: $keys['public_key'];
            }

            if ((int) ($site->vpn_public_port ?? 0) !== Site::defaultVpnPublicPort()) {
                $updates['vpn_public_port'] = Site::defaultVpnPublicPort();
            }

            if ($updates) {
                DB::connection('central')->table('sites')->where('id', $site->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            foreach (['wireguard_preshared_key', 'wireguard_public_key', 'wireguard_private_key'] as $column) {
                if (Schema::connection('central')->hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
