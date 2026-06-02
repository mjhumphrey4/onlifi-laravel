<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->connectionsWithVoucherTemplates() as $connection) {
            DB::connection($connection)->statement(
                "ALTER TABLE voucher_templates MODIFY layout ENUM('single','grid-2x2','grid-2x4','grid-3x3','grid-4x5','grid-5x8','grid-8x10') NOT NULL DEFAULT 'grid-2x4'"
            );
        }
    }

    public function down(): void
    {
        foreach ($this->connectionsWithVoucherTemplates() as $connection) {
            DB::connection($connection)
                ->table('voucher_templates')
                ->whereIn('layout', ['grid-4x5', 'grid-5x8', 'grid-8x10'])
                ->update(['layout' => 'grid-3x3']);

            DB::connection($connection)->statement(
                "ALTER TABLE voucher_templates MODIFY layout ENUM('single','grid-2x2','grid-2x4','grid-3x3') NOT NULL DEFAULT 'grid-2x4'"
            );
        }
    }

    private function connectionsWithVoucherTemplates(): array
    {
        $connections = array_values(array_unique(array_filter([
            config('database.default'),
            'central',
        ])));

        $seenDatabases = [];

        return array_values(array_filter($connections, function (string $connection) use (&$seenDatabases) {
            if (!Schema::connection($connection)->hasTable('voucher_templates')) {
                return false;
            }

            $database = (string) config("database.connections.{$connection}.database");
            $key = $database !== '' ? $database : $connection;

            if (isset($seenDatabases[$key])) {
                return false;
            }

            $seenDatabases[$key] = true;
            return true;
        }));
    }
};
