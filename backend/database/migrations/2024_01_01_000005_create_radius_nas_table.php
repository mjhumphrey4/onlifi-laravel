<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NAS (Network Access Server) table for FreeRADIUS
 * 
 * This table maps MikroTik routers to tenants, allowing FreeRADIUS
 * to know which tenant database to query for authentication.
 * 
 * Since routers often have dynamic IPs, we use a unique router_identifier
 * that is sent with each RADIUS request (via NAS-Identifier attribute).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('nas', function (Blueprint $table) {
            $table->id();
            $table->string('nasname', 128);  // Can be IP or hostname (for FreeRADIUS compatibility)
            $table->string('router_identifier', 64)->unique();  // Unique router ID sent in RADIUS requests
            $table->string('shortname', 32)->nullable();
            $table->string('type', 30)->default('other');
            $table->integer('ports')->nullable();
            $table->string('secret', 60);  // RADIUS shared secret (shared across all routers for simplicity)
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->nullable();
            
            // Link to tenant for multi-tenant routing
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            // Link to router in tenant database (for reference)
            $table->unsignedBigInteger('router_id')->nullable();
            
            $table->timestamps();
            
            $table->index('nasname');
            $table->index('router_identifier');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('nas');
    }
};
