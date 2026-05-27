<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('voucher_templates')) {
            return;
        }

        Schema::connection('central')->table('voucher_templates', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('voucher_templates', 'site_id')) {
                $table->foreignId('site_id')->nullable()->after('tenant_id')->constrained('sites')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('voucher_templates') || !Schema::connection('central')->hasColumn('voucher_templates', 'site_id')) {
            return;
        }

        Schema::connection('central')->table('voucher_templates', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });
    }
};
