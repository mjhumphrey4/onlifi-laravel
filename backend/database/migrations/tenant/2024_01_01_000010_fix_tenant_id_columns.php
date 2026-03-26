<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';
    
    public function up(): void
    {
        // Remove tenant_id columns from tables that shouldn't have them
        // Each tenant has their own database, so tenant_id is not needed
        
        $tables = [
            'transactions',
            'voucher_sales_points',
            'voucher_types',
            'voucher_groups',
            'vouchers',
            'mikrotik_routers',
            'router_telemetry',
        ];
        
        foreach ($tables as $table) {
            if (Schema::connection('tenant')->hasTable($table) && 
                Schema::connection('tenant')->hasColumn($table, 'tenant_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropColumn('tenant_id');
                });
            }
        }
    }

    public function down(): void
    {
        // Not reversible - tenant_id columns are not needed
    }
};
