<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
            return;
        }

        Schema::connection('tenant')->table('hotspot_users', function (Blueprint $table) {
            if (!Schema::connection('tenant')->hasColumn('hotspot_users', 'hostname')) {
                $table->string('hostname', 100)->nullable()->after('username');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
            return;
        }

        Schema::connection('tenant')->table('hotspot_users', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('hotspot_users', 'hostname')) {
                $table->dropColumn('hostname');
            }
        });
    }
};
