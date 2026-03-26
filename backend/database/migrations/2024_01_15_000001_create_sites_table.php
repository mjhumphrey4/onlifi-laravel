<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('api_token', 64)->unique();
            $table->timestamps();
        });

        // Add site_id to mikrotik_routers if not exists
        if (!Schema::hasColumn('mikrotik_routers', 'site_id')) {
            Schema::table('mikrotik_routers', function (Blueprint $table) {
                $table->foreignId('site_id')->nullable()->after('id')->constrained('sites')->nullOnDelete();
            });
        }

        // Add site_id to voucher_groups if not exists
        if (!Schema::hasColumn('voucher_groups', 'site_id')) {
            Schema::table('voucher_groups', function (Blueprint $table) {
                $table->foreignId('site_id')->nullable()->after('id')->constrained('sites')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mikrotik_routers', 'site_id')) {
            Schema::table('mikrotik_routers', function (Blueprint $table) {
                $table->dropForeign(['site_id']);
                $table->dropColumn('site_id');
            });
        }

        if (Schema::hasColumn('voucher_groups', 'site_id')) {
            Schema::table('voucher_groups', function (Blueprint $table) {
                $table->dropForeign(['site_id']);
                $table->dropColumn('site_id');
            });
        }

        Schema::dropIfExists('sites');
    }
};
