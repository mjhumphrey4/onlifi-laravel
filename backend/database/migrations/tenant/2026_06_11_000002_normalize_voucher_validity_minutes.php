<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $this->normalizeHoursTable('voucher_groups', 'validity_hours');
        $this->normalizeHoursTable('vouchers', 'validity_hours');
        $this->normalizeHoursTable('voucher_types', 'duration_hours');
        $this->repairTooShortActiveVoucherExpiry();
    }

    public function down(): void
    {
        //
    }

    private function normalizeHoursTable(string $table, string $hoursColumn): void
    {
        if (
            !Schema::connection('tenant')->hasTable($table)
            || !Schema::connection('tenant')->hasColumn($table, $hoursColumn)
            || !Schema::connection('tenant')->hasColumn($table, 'validity_minutes')
        ) {
            return;
        }

        DB::connection('tenant')->statement("
            UPDATE `$table`
            SET validity_minutes = `$hoursColumn` * 60
            WHERE `$hoursColumn` IS NOT NULL
              AND `$hoursColumn` > 0
              AND (validity_minutes IS NULL OR validity_minutes < `$hoursColumn` * 60)
        ");
    }

    private function repairTooShortActiveVoucherExpiry(): void
    {
        foreach (['vouchers'] as $table) {
            if (
                !Schema::connection('tenant')->hasTable($table)
                || !Schema::connection('tenant')->hasColumn($table, 'validity_minutes')
                || !Schema::connection('tenant')->hasColumn($table, 'first_used_at')
                || !Schema::connection('tenant')->hasColumn($table, 'expires_at')
            ) {
                continue;
            }

            DB::connection('tenant')->statement("
                UPDATE `$table`
                SET expires_at = DATE_ADD(first_used_at, INTERVAL validity_minutes MINUTE),
                    status = CASE
                        WHEN status = 'used'
                         AND expired_reason = 'time_limit'
                         AND DATE_ADD(first_used_at, INTERVAL validity_minutes MINUTE) > NOW()
                            THEN 'in_use'
                        ELSE status
                    END,
                    expired_reason = CASE
                        WHEN status = 'used'
                         AND expired_reason = 'time_limit'
                         AND DATE_ADD(first_used_at, INTERVAL validity_minutes MINUTE) > NOW()
                            THEN NULL
                        ELSE expired_reason
                    END
                WHERE first_used_at IS NOT NULL
                  AND validity_minutes IS NOT NULL
                  AND validity_minutes > 0
                  AND (
                    status IN ('reserved', 'in_use')
                    OR (status = 'used' AND expired_reason = 'time_limit' AND DATE_ADD(first_used_at, INTERVAL validity_minutes MINUTE) > NOW())
                  )
                  AND (
                    expires_at IS NULL
                    OR expires_at < DATE_ADD(first_used_at, INTERVAL validity_minutes MINUTE)
                  )
            ");
        }
    }
};
