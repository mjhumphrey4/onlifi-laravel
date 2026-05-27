<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('sites', 'vpn_private_ip')) {
                $table->string('vpn_private_ip', 45)->nullable()->after('api_token');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'vpn_username')) {
                $table->string('vpn_username', 100)->nullable()->after('vpn_private_ip');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'vpn_status')) {
                $table->string('vpn_status', 32)->default('pending')->after('vpn_username');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'vpn_last_seen_at')) {
                $table->timestamp('vpn_last_seen_at')->nullable()->after('vpn_status');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'router_api_port')) {
                $table->integer('router_api_port')->default(8728)->after('vpn_last_seen_at');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'remote_access_notes')) {
                $table->text('remote_access_notes')->nullable()->after('router_api_port');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            foreach (['remote_access_notes', 'router_api_port', 'vpn_last_seen_at', 'vpn_status', 'vpn_username', 'vpn_private_ip'] as $column) {
                if (Schema::connection('central')->hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
