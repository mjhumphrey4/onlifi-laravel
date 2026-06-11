<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (!Schema::connection('tenant')->hasTable('mikrotik_routers')) {
            return;
        }

        if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
            Schema::connection('tenant')->table('mikrotik_routers', function (Blueprint $table) {
                $table->unsignedBigInteger('site_id')->nullable()->after('id')->index();
            });
        }

        $this->backfillFromSiteDatabaseName();
        $this->backfillFromInstallerSubmissions();
    }

    public function down(): void
    {
        if (!Schema::connection('tenant')->hasTable('mikrotik_routers') || !Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
            return;
        }

        Schema::connection('tenant')->table('mikrotik_routers', function (Blueprint $table) {
            $table->dropColumn('site_id');
        });
    }

    private function backfillFromSiteDatabaseName(): void
    {
        $database = DB::connection('tenant')->getDatabaseName();

        if (!preg_match('/^onlifi_(\d+)_(\d+)_/i', (string) $database, $matches)) {
            return;
        }

        DB::connection('tenant')
            ->table('mikrotik_routers')
            ->whereNull('site_id')
            ->update(['site_id' => (int) $matches[2]]);
    }

    private function backfillFromInstallerSubmissions(): void
    {
        if (!Schema::connection('central')->hasTable('installer_device_submissions')) {
            return;
        }

        if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'installer_submission_id')) {
            return;
        }

        $routers = DB::connection('tenant')
            ->table('mikrotik_routers')
            ->whereNull('site_id')
            ->whereNotNull('installer_submission_id')
            ->get(['id', 'installer_submission_id']);

        foreach ($routers as $router) {
            $submission = DB::connection('central')
                ->table('installer_device_submissions')
                ->where('id', $router->installer_submission_id)
                ->first(['site_id']);

            if (!$submission?->site_id) {
                continue;
            }

            DB::connection('tenant')
                ->table('mikrotik_routers')
                ->where('id', $router->id)
                ->update(['site_id' => $submission->site_id]);
        }
    }
};
