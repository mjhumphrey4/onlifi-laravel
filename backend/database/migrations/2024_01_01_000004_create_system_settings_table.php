<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->string('group')->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });

        DB::connection('central')->table('system_settings')->insert([
            ['key' => 'site_name', 'value' => 'OnLiFi Payment System', 'type' => 'string', 'group' => 'general', 'is_public' => true],
            ['key' => 'default_trial_days', 'value' => '30', 'type' => 'integer', 'group' => 'tenants', 'is_public' => false],
            ['key' => 'auto_approve_tenants', 'value' => 'false', 'type' => 'boolean', 'group' => 'tenants', 'is_public' => false],
            ['key' => 'maintenance_mode', 'value' => 'false', 'type' => 'boolean', 'group' => 'system', 'is_public' => true],
            ['key' => 'allow_tenant_signup', 'value' => 'true', 'type' => 'boolean', 'group' => 'tenants', 'is_public' => true],
        ]);
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('system_settings');
    }
};
