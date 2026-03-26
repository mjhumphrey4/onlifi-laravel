<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_telemetry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('mikrotik_routers')->cascadeOnDelete();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('router_identity', 100);
            $table->string('router_version', 50)->nullable();
            $table->string('router_board', 100)->nullable();
            $table->decimal('cpu_load', 5, 2)->default(0);
            $table->bigInteger('memory_total_mb')->default(0);
            $table->bigInteger('memory_used_mb')->default(0);
            $table->bigInteger('uptime_seconds')->default(0);
            $table->integer('active_connections')->default(0);
            $table->bigInteger('bandwidth_download_kbps')->default(0);
            $table->bigInteger('bandwidth_upload_kbps')->default(0);
            $table->bigInteger('total_tx_bytes')->default(0);
            $table->bigInteger('total_rx_bytes')->default(0);
            $table->timestamp('timestamp')->nullable();
            $table->timestamp('created_at')->nullable();
            
            $table->index(['router_id', 'created_at']);
            $table->index(['site_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_telemetry');
    }
};
