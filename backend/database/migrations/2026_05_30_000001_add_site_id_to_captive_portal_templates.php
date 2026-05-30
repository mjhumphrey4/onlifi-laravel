<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('captive_portal_templates')) {
            return;
        }

        Schema::connection('central')->table('captive_portal_templates', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('captive_portal_templates', 'site_id')) {
                $table->unsignedBigInteger('site_id')->nullable()->after('tenant_id')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('captive_portal_templates') || !Schema::connection('central')->hasColumn('captive_portal_templates', 'site_id')) {
            return;
        }

        Schema::connection('central')->table('captive_portal_templates', function (Blueprint $table) {
            $table->dropColumn('site_id');
        });
    }
};
