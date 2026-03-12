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
            $table->decimal('cpu_load', 5, 2)->nullable();
            $table->integer('memory_used_mb')->nullable();
            $table->integer('memory_total_mb')->nullable();
            $table->bigInteger('uptime_seconds')->nullable();
            $table->integer('active_connections')->nullable();
            $table->integer('total_clients')->nullable();
            $table->decimal('bandwidth_upload_kbps', 10, 2)->nullable();
            $table->decimal('bandwidth_download_kbps', 10, 2)->nullable();
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
            
            $table->index(['router_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_telemetry');
    }
};
