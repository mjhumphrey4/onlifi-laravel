<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('sites', 'site_type')) {
                $table->string('site_type', 32)->default('mikrotik')->after('description');
            }
        });

        DB::connection('central')
            ->table('sites')
            ->whereNull('site_type')
            ->update(['site_type' => 'mikrotik']);

        DB::connection('central')
            ->table('sites')
            ->where(function ($query) {
                $query->whereNull('vpn_public_host')
                    ->orWhere('vpn_public_host', '');
            })
            ->update(['vpn_public_host' => '89.167.42.53']);

        DB::connection('central')
            ->table('sites')
            ->where(function ($query) {
                $query->whereNull('vpn_public_port')
                    ->orWhere('vpn_public_port', 443);
            })
            ->update(['vpn_public_port' => 8443]);
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            if (Schema::connection('central')->hasColumn('sites', 'site_type')) {
                $table->dropColumn('site_type');
            }
        });
    }
};
