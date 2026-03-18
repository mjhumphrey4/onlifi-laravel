<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('mikrotik_routers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('ip_address', 15)->unique();
            $table->integer('api_port')->default(8728);
            $table->string('username', 64);
            $table->string('password');
            $table->string('location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('mikrotik_routers');
    }
};
