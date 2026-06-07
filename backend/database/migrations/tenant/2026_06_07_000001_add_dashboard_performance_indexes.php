<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        $this->addIndexIfMissing('transactions', 'onlifi_tx_site_status_created_idx', ['site_id', 'status', 'created_at']);
        $this->addIndexIfMissing('transactions', 'onlifi_tx_origin_status_created_idx', ['origin_site', 'status', 'created_at']);

        $this->addIndexIfMissing('vouchers', 'onlifi_v_site_first_used_idx', ['site_id', 'first_used_at']);
        $this->addIndexIfMissing('vouchers', 'onlifi_v_site_status_created_idx', ['site_id', 'status', 'created_at']);
        $this->addIndexIfMissing('vouchers', 'onlifi_v_site_expires_idx', ['site_id', 'expires_at']);
        $this->addIndexIfMissing('vouchers', 'onlifi_v_group_first_used_idx', ['group_id', 'first_used_at']);

        $this->addIndexIfMissing('hotspot_users', 'onlifi_hu_site_last_seen_idx', ['site_id', 'last_seen']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('hotspot_users', 'onlifi_hu_site_last_seen_idx');

        $this->dropIndexIfExists('vouchers', 'onlifi_v_group_first_used_idx');
        $this->dropIndexIfExists('vouchers', 'onlifi_v_site_expires_idx');
        $this->dropIndexIfExists('vouchers', 'onlifi_v_site_status_created_idx');
        $this->dropIndexIfExists('vouchers', 'onlifi_v_site_first_used_idx');

        $this->dropIndexIfExists('transactions', 'onlifi_tx_origin_status_created_idx');
        $this->dropIndexIfExists('transactions', 'onlifi_tx_site_status_created_idx');
    }

    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if (!$this->tableReady($table, $columns) || $this->indexExists($table, $index)) {
            return;
        }

        $quotedColumns = collect($columns)
            ->map(fn (string $column) => "`{$column}`")
            ->implode(', ');

        DB::connection('tenant')->statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$quotedColumns})");
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (!Schema::connection('tenant')->hasTable($table) || !$this->indexExists($table, $index)) {
            return;
        }

        DB::connection('tenant')->statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
    }

    private function tableReady(string $table, array $columns): bool
    {
        if (!Schema::connection('tenant')->hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (!Schema::connection('tenant')->hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    private function indexExists(string $table, string $index): bool
    {
        $result = DB::connection('tenant')->selectOne("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);

        return $result !== null;
    }
};
