<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TenantRouterSchema
{
    public static function ensureForSite(?Site $site = null): void
    {
        if (!Schema::connection('tenant')->hasTable('mikrotik_routers')) {
            return;
        }

        self::addMissingColumns();
        self::backfillFromTenantDatabaseName();

        if ($site) {
            self::backfillForSite($site);
        }
    }

    private static function addMissingColumns(): void
    {
        $columns = [
            'site_id' => fn (Blueprint $table) => $table->unsignedBigInteger('site_id')->nullable()->after('id')->index(),
            'latitude' => fn (Blueprint $table) => $table->decimal('latitude', 10, 7)->nullable()->after('location'),
            'longitude' => fn (Blueprint $table) => $table->decimal('longitude', 10, 7)->nullable()->after('latitude'),
            'installed_by_user_id' => fn (Blueprint $table) => $table->unsignedBigInteger('installed_by_user_id')->nullable()->after('longitude'),
            'installed_at' => fn (Blueprint $table) => $table->timestamp('installed_at')->nullable()->after('installed_by_user_id'),
            'installer_submission_id' => fn (Blueprint $table) => $table->unsignedBigInteger('installer_submission_id')->nullable()->after('installed_at')->index(),
            'uptime_kuma_monitor_id' => fn (Blueprint $table) => $table->string('uptime_kuma_monitor_id')->nullable()->after('installer_submission_id'),
        ];

        foreach ($columns as $column => $definition) {
            if (Schema::connection('tenant')->hasColumn('mikrotik_routers', $column)) {
                continue;
            }

            try {
                Schema::connection('tenant')->table('mikrotik_routers', function (Blueprint $table) use ($definition) {
                    $definition($table);
                });
            } catch (\Throwable $e) {
                Log::warning('Could not repair mikrotik_routers schema column', [
                    'column' => $column,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private static function backfillFromTenantDatabaseName(): void
    {
        if (!Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')) {
            return;
        }

        $database = DB::connection('tenant')->getDatabaseName();

        if (!preg_match('/^onlifi_(\d+)_(\d+)_/i', (string) $database, $matches)) {
            return;
        }

        DB::connection('tenant')
            ->table('mikrotik_routers')
            ->whereNull('site_id')
            ->update(['site_id' => (int) $matches[2]]);
    }

    private static function backfillForSite(Site $site): void
    {
        if (
            !Schema::connection('central')->hasTable('installer_device_submissions')
            || !Schema::connection('tenant')->hasColumn('mikrotik_routers', 'site_id')
            || !Schema::connection('tenant')->hasColumn('mikrotik_routers', 'installer_submission_id')
        ) {
            return;
        }

        $submissionIds = DB::connection('central')
            ->table('installer_device_submissions')
            ->where('tenant_id', $site->tenant_id)
            ->where('site_id', $site->id)
            ->pluck('id');

        if ($submissionIds->isEmpty()) {
            return;
        }

        DB::connection('tenant')
            ->table('mikrotik_routers')
            ->whereNull('site_id')
            ->whereIn('installer_submission_id', $submissionIds)
            ->update(['site_id' => $site->id]);
    }
}
