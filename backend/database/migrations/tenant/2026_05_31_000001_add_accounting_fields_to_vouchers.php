<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        foreach (['voucher_types', 'voucher_groups', 'vouchers'] as $tableName) {
            if (Schema::connection('tenant')->hasTable($tableName) && !Schema::connection('tenant')->hasColumn($tableName, 'validity_minutes')) {
                $afterColumn = match (true) {
                    Schema::connection('tenant')->hasColumn($tableName, 'validity_hours') => 'validity_hours',
                    Schema::connection('tenant')->hasColumn($tableName, 'duration_hours') => 'duration_hours',
                    default => null,
                };

                Schema::connection('tenant')->table($tableName, function (Blueprint $table) use ($afterColumn) {
                    $column = $table->integer('validity_minutes')->nullable();
                    if ($afterColumn) {
                        $column->after($afterColumn);
                    }
                });
            }
        }

        if (Schema::connection('tenant')->hasTable('vouchers')) {
            Schema::connection('tenant')->table('vouchers', function (Blueprint $table) {
                if (!Schema::connection('tenant')->hasColumn('vouchers', 'total_session_time_minutes')) {
                    $table->integer('total_session_time_minutes')->default(0)->after('total_data_used_mb');
                }
                if (!Schema::connection('tenant')->hasColumn('vouchers', 'last_accounting_at')) {
                    $table->timestamp('last_accounting_at')->nullable()->after('total_session_time_minutes');
                }
                if (!Schema::connection('tenant')->hasColumn('vouchers', 'expired_reason')) {
                    $table->string('expired_reason', 80)->nullable()->after('last_accounting_at');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['voucher_types', 'voucher_groups', 'vouchers'] as $tableName) {
            if (Schema::connection('tenant')->hasTable($tableName) && Schema::connection('tenant')->hasColumn($tableName, 'validity_minutes')) {
                Schema::connection('tenant')->table($tableName, function (Blueprint $table) {
                    $table->dropColumn('validity_minutes');
                });
            }
        }

        if (Schema::connection('tenant')->hasTable('vouchers')) {
            Schema::connection('tenant')->table('vouchers', function (Blueprint $table) {
                foreach (['total_session_time_minutes', 'last_accounting_at', 'expired_reason'] as $column) {
                    if (Schema::connection('tenant')->hasColumn('vouchers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
