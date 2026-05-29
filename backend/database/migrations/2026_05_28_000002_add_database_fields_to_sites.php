<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('sites', 'database_name')) {
                $table->string('database_name')->nullable()->after('api_token');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'database_host')) {
                $table->string('database_host')->nullable()->after('database_name');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'database_port')) {
                $table->unsignedInteger('database_port')->nullable()->after('database_host');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'database_username')) {
                $table->string('database_username')->nullable()->after('database_port');
            }
            if (!Schema::connection('central')->hasColumn('sites', 'database_password')) {
                $table->string('database_password')->nullable()->after('database_username');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            foreach (['database_password', 'database_username', 'database_port', 'database_host', 'database_name'] as $column) {
                if (Schema::connection('central')->hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
