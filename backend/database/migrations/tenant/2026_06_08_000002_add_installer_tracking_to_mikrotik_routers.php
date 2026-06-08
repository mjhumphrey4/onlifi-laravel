<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mikrotik_routers')) {
            return;
        }

        Schema::connection('tenant')->table('mikrotik_routers', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('location');
            }
            if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'installed_by_user_id')) {
                $table->unsignedBigInteger('installed_by_user_id')->nullable()->after('longitude')->index();
            }
            if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'installed_at')) {
                $table->timestamp('installed_at')->nullable()->after('installed_by_user_id');
            }
            if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'installer_submission_id')) {
                $table->unsignedBigInteger('installer_submission_id')->nullable()->after('installed_at')->index();
            }
            if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'uptime_kuma_monitor_id')) {
                $table->string('uptime_kuma_monitor_id', 100)->nullable()->after('installer_submission_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('mikrotik_routers')) {
            return;
        }

        Schema::connection('tenant')->table('mikrotik_routers', function (Blueprint $table) {
            foreach (['uptime_kuma_monitor_id', 'installer_submission_id', 'installed_at', 'installed_by_user_id', 'longitude', 'latitude'] as $column) {
                if (Schema::connection('tenant')->hasColumn('mikrotik_routers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
