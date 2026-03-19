<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('domain')->nullable()->unique();
            $table->string('database_name')->unique();
            $table->string('database_host')->default('127.0.0.1');
            $table->integer('database_port')->default(3306);
            $table->string('database_username');
            $table->string('database_password')->nullable();
            $table->string('api_key')->unique();
            $table->string('api_secret');
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending');
            $table->boolean('is_active')->default(false);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('super_admins')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->json('settings')->nullable();
            
            // Per-tenant YoAPI credentials (for direct payments to tenant)
            $table->string('yoapi_username')->nullable();
            $table->string('yoapi_password')->nullable();
            $table->enum('yoapi_mode', ['sandbox', 'production'])->default('sandbox');
            
            // Per-tenant RADIUS secret (for FreeRADIUS authentication)
            $table->string('radius_secret')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
