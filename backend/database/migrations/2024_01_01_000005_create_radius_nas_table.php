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
 * FreeRADIUS reads this table to:
 * 1. Validate the RADIUS client (router) is authorized
 * 2. Get the shared secret for that client
 * 3. Determine which tenant database to use for auth
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('nas', function (Blueprint $table) {
            $table->id();
            $table->string('nasname', 128)->unique();  // Router IP address
            $table->string('shortname', 32)->nullable();
            $table->string('type', 30)->default('other');
            $table->integer('ports')->nullable();
            $table->string('secret', 60);  // RADIUS shared secret
            $table->string('server', 64)->nullable();
            $table->string('community', 50)->nullable();
            $table->string('description', 200)->nullable();
            
            // Link to tenant for multi-tenant routing
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            
            $table->timestamps();
            
            $table->index('nasname');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('nas');
    }
};
