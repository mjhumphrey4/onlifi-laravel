<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        foreach (['transactions', 'voucher_sales_points', 'voucher_groups', 'vouchers'] as $tableName) {
            if (Schema::connection('tenant')->hasTable($tableName) && !Schema::connection('tenant')->hasColumn($tableName, 'site_id')) {
                Schema::connection('tenant')->table($tableName, function (Blueprint $table) {
                    $table->unsignedBigInteger('site_id')->nullable()->index();
                });
            }

            if (Schema::connection('tenant')->hasTable($tableName) && Schema::connection('tenant')->hasColumn($tableName, 'tenant_id')) {
                try {
                    DB::connection('tenant')->statement("ALTER TABLE `{$tableName}` MODIFY `tenant_id` BIGINT UNSIGNED NULL");
                } catch (\Throwable $e) {
                    // Older tenant DBs may already have different compatible definitions.
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['transactions', 'voucher_sales_points', 'voucher_groups', 'vouchers'] as $tableName) {
            if (Schema::connection('tenant')->hasTable($tableName) && Schema::connection('tenant')->hasColumn($tableName, 'site_id')) {
                Schema::connection('tenant')->table($tableName, function (Blueprint $table) {
                    $table->dropColumn('site_id');
                });
            }
        }
    }
};
