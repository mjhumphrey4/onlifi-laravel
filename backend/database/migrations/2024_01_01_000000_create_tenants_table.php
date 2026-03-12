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
            $table->string('database_password');
            $table->string('api_key')->unique();
            $table->string('api_secret');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenants');
    }
};
