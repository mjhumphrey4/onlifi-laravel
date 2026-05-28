<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('api_token', 64)->unique();
            $table->string('vpn_private_ip')->nullable();
            $table->string('vpn_username')->nullable();
            $table->string('vpn_password')->nullable();
            $table->string('vpn_public_host')->nullable();
            $table->unsignedInteger('vpn_public_port')->nullable();
            $table->string('vpn_status')->default('active');
            $table->timestamp('vpn_last_seen_at')->nullable();
            $table->unsignedInteger('router_api_port')->nullable();
            $table->text('remote_access_notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sites');
    }
};
