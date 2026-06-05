<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('hotspot_users')) {
            return;
        }

        Schema::connection('tenant')->create('hotspot_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('mac_address', 32);
            $table->string('ip_address', 45)->nullable();
            $table->string('username', 100)->nullable();
            $table->string('hostname', 100)->nullable();
            $table->string('device_type', 100)->nullable();
            $table->unsignedInteger('uptime_seconds')->default(0);
            $table->decimal('data_uploaded_mb', 12, 2)->default(0);
            $table->decimal('data_downloaded_mb', 12, 2)->default(0);
            $table->integer('signal_strength')->nullable();
            $table->timestamp('last_seen')->nullable()->index();
            $table->string('router_name', 100)->nullable();
            $table->string('router_identity', 100)->nullable();
            $table->string('voucher_code', 64)->nullable()->index();
            $table->string('profile_name', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'mac_address']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('hotspot_users');
    }
};
