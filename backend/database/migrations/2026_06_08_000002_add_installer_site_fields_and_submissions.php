<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('sites')) {
            Schema::connection('central')->table('sites', function (Blueprint $table) {
                if (!Schema::connection('central')->hasColumn('sites', 'assigned_device_ip_range')) {
                    $table->string('assigned_device_ip_range', 64)->nullable()->after('remote_access_notes');
                }
            });
        }

        if (!Schema::connection('central')->hasTable('installer_device_submissions')) {
            Schema::connection('central')->create('installer_device_submissions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('installer_user_id')->index();
                $table->unsignedBigInteger('router_id')->nullable()->index();
                $table->string('local_id', 100);
                $table->string('device_name', 100);
                $table->string('ip_address', 45);
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->string('front_photo_path')->nullable();
                $table->string('back_photo_path')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('mobile_created_at')->nullable();
                $table->timestamps();

                $table->unique(['installer_user_id', 'local_id']);
                $table->unique(['tenant_id', 'ip_address']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('installer_device_submissions');

        if (Schema::connection('central')->hasTable('sites') && Schema::connection('central')->hasColumn('sites', 'assigned_device_ip_range')) {
            Schema::connection('central')->table('sites', function (Blueprint $table) {
                $table->dropColumn('assigned_device_ip_range');
            });
        }
    }
};
