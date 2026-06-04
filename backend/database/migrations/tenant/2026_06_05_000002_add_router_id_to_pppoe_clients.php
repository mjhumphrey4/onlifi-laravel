<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('pppoe_clients')) {
            return;
        }

        Schema::connection('tenant')->table('pppoe_clients', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('pppoe_clients', 'router_id')) {
                $table->string('router_id', 64)->nullable()->after('site_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('pppoe_clients')) {
            return;
        }

        Schema::connection('tenant')->table('pppoe_clients', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('pppoe_clients', 'router_id')) {
                $table->dropColumn('router_id');
            }
        });
    }
};
