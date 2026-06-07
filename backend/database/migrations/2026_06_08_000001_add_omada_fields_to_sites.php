<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            if (!Schema::connection('central')->hasColumn('sites', 'omada_site_name')) {
                $table->string('omada_site_name', 100)->nullable()->after('site_type');
            }

            if (!Schema::connection('central')->hasColumn('sites', 'omada_site_id')) {
                $table->string('omada_site_id', 100)->nullable()->after('omada_site_name');
            }

            if (!Schema::connection('central')->hasColumn('sites', 'omada_controller_id')) {
                $table->string('omada_controller_id', 100)->nullable()->after('omada_site_id');
            }

            if (!Schema::connection('central')->hasColumn('sites', 'omada_link_status')) {
                $table->string('omada_link_status', 32)->default('not_required')->after('omada_controller_id')->index();
            }

            if (!Schema::connection('central')->hasColumn('sites', 'omada_linked_at')) {
                $table->timestamp('omada_linked_at')->nullable()->after('omada_link_status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::connection('central')->hasTable('sites')) {
            return;
        }

        Schema::connection('central')->table('sites', function (Blueprint $table) {
            if (Schema::connection('central')->hasColumn('sites', 'omada_link_status')) {
                $table->dropIndex(['omada_link_status']);
            }

            foreach (['omada_linked_at', 'omada_link_status', 'omada_controller_id', 'omada_site_id', 'omada_site_name'] as $column) {
                if (Schema::connection('central')->hasColumn('sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
