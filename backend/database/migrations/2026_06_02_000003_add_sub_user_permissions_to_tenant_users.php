<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('tenant_users')) {
            return;
        }

        Schema::connection('central')->table('tenant_users', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('tenant_users', 'allowed_site_ids')) {
                $table->json('allowed_site_ids')->nullable()->after('role');
            }
            if (!Schema::connection('central')->hasColumn('tenant_users', 'permissions')) {
                $table->json('permissions')->nullable()->after('allowed_site_ids');
            }
            if (!Schema::connection('central')->hasColumn('tenant_users', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('permissions');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('tenant_users')) {
            return;
        }

        Schema::connection('central')->table('tenant_users', function (Blueprint $table) {
            foreach (['created_by', 'permissions', 'allowed_site_ids'] as $column) {
                if (Schema::connection('central')->hasColumn('tenant_users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
