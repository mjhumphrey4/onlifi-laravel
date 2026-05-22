<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add tenant_id to sites and router_telemetry tables for proper
     * multi-tenant telemetry data isolation.
     */
    public function up(): void
    {
        // Add tenant_id to sites table (central database)
        if (Schema::connection('central')->hasTable('sites')) {
            if (!Schema::connection('central')->hasColumn('sites', 'tenant_id')) {
                Schema::connection('central')->table('sites', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('id')->index();
                });
            }
        }

        // Add tenant_id to router_telemetry table (central database)
        if (Schema::connection('central')->hasTable('router_telemetry')) {
            if (!Schema::connection('central')->hasColumn('router_telemetry', 'tenant_id')) {
                Schema::connection('central')->table('router_telemetry', function (Blueprint $table) {
                    $table->unsignedBigInteger('tenant_id')->nullable()->after('site_id')->index();
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('central')->hasTable('sites') &&
            Schema::connection('central')->hasColumn('sites', 'tenant_id')) {
            Schema::connection('central')->table('sites', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::connection('central')->hasTable('router_telemetry') &&
            Schema::connection('central')->hasColumn('router_telemetry', 'tenant_id')) {
            Schema::connection('central')->table('router_telemetry', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
