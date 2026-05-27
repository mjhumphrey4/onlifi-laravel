<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('nas')) {
            return;
        }

        Schema::connection('central')->table('nas', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('nas', 'site_id')) {
                $table->foreignId('site_id')->nullable()->after('tenant_id')->constrained('sites')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('nas') || !Schema::connection('central')->hasColumn('nas', 'site_id')) {
            return;
        }

        Schema::connection('central')->table('nas', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });
    }
};
