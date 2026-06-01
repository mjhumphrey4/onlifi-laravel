<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('vouchers')) {
            return;
        }

        DB::connection('tenant')->statement(
            "ALTER TABLE vouchers MODIFY status ENUM('unused','reserved','in_use','used','expired','disabled') NOT NULL DEFAULT 'unused'"
        );
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('vouchers')) {
            return;
        }

        DB::connection('tenant')->table('vouchers')
            ->whereIn('status', ['reserved', 'in_use'])
            ->update(['status' => 'used']);

        DB::connection('tenant')->statement(
            "ALTER TABLE vouchers MODIFY status ENUM('unused','used','expired','disabled') NOT NULL DEFAULT 'unused'"
        );
    }
};
