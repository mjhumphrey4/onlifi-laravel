<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('mikrotik_routers', function (Blueprint $table) {
            $table->decimal('last_cpu_load', 5, 2)->nullable()->after('last_seen');
            $table->integer('last_memory_used_mb')->nullable()->after('last_cpu_load');
            $table->integer('memory_total_mb')->nullable()->after('last_memory_used_mb');
            $table->integer('last_active_connections')->nullable()->after('memory_total_mb');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn([
                'last_cpu_load',
                'last_memory_used_mb',
                'memory_total_mb',
                'last_active_connections',
            ]);
        });
    }
};
