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
            if (!Schema::connection('tenant')->hasColumn('hotspot_users', 'voucher_type')) {
                $table->string('voucher_type', 100)->nullable()->after('profile_name');
            }

            if (!Schema::connection('tenant')->hasColumn('hotspot_users', 'first_used_at')) {
                $table->timestamp('first_used_at')->nullable()->after('voucher_type');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('hotspot_users')) {
            return;
        }

        Schema::connection('tenant')->table('hotspot_users', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('hotspot_users', 'first_used_at')) {
                $table->dropColumn('first_used_at');
            }

            if (Schema::connection('tenant')->hasColumn('hotspot_users', 'voucher_type')) {
                $table->dropColumn('voucher_type');
            }
        });
    }
};
