<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('router_telemetry')) {
            return;
        }

        Schema::connection('central')->table('router_telemetry', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('router_telemetry', 'wan_interfaces')) {
                $table->text('wan_interfaces')->nullable()->after('total_rx_bytes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('router_telemetry')) {
            return;
        }

        Schema::connection('central')->table('router_telemetry', function (Blueprint $table) {
            if (Schema::connection('central')->hasColumn('router_telemetry', 'wan_interfaces')) {
                $table->dropColumn('wan_interfaces');
            }
        });
    }
};
