<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';
    
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('router_telemetry')) {
            return;
        }
        
        Schema::connection('central')->create('router_telemetry', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('router_id')->nullable();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('router_identity')->nullable();
            $table->string('router_version')->nullable();
            $table->string('router_board')->nullable();
            $table->decimal('cpu_load', 5, 2)->nullable();
            $table->integer('memory_used_mb')->nullable();
            $table->integer('memory_total_mb')->nullable();
            $table->bigInteger('uptime_seconds')->nullable();
            $table->integer('active_connections')->nullable();
            $table->decimal('bandwidth_upload_kbps', 10, 2)->nullable();
            $table->decimal('bandwidth_download_kbps', 10, 2)->nullable();
            $table->bigInteger('total_tx_bytes')->nullable();
            $table->bigInteger('total_rx_bytes')->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->timestamps();
            
            $table->index(['site_id', 'created_at']);
            $table->index(['router_identity', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('router_telemetry');
    }
};
