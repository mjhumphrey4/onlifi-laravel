<?php

namespace App\Support;

use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SiteScope
{
    public static function selectedSite(Request $request): ?Site
    {
        $siteId = $request->header('X-Site-ID') ?: $request->query('site_id');

        if (!$siteId || !is_numeric($siteId)) {
            return null;
        }

        $tenantId = $request->user()?->tenant_id ?? (app()->bound('tenant') ? app('tenant')->id : null);

        if (!$tenantId) {
            return null;
        }

        return Site::where('tenant_id', $tenantId)
            ->where('id', (int) $siteId)
            ->first();
    }

    public static function defaultSite(Request $request): ?Site
    {
        $tenantId = $request->user()?->tenant_id ?? (app()->bound('tenant') ? app('tenant')->id : null);

        if (!$tenantId) {
            return null;
        }

        $site = Site::where('tenant_id', $tenantId)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();

        if ($site) {
            return $site;
        }

        $tenant = Tenant::find($tenantId);
        $name = $tenant?->name ?: 'Default Site';

        return Site::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => 'Default site created for existing tenant data.',
            'is_active' => true,
            'vpn_username' => Str::slug($name),
            'vpn_password' => Str::random(24),
            'vpn_public_host' => 'vpn.onlifi.net',
            'vpn_status' => 'pending',
        ]);
    }

    public static function selectedOrDefaultSite(Request $request): ?Site
    {
        return self::selectedSite($request) ?: self::defaultSite($request);
    }

    public static function backfillLegacyTenantSite(?Site $site, array $tables): void
    {
        if (!$site) {
            return;
        }

        self::ensureTenantSiteColumns($tables);

        foreach ($tables as $table) {
            if (!Schema::connection('tenant')->hasTable($table) || !Schema::connection('tenant')->hasColumn($table, 'site_id')) {
                continue;
            }

            DB::connection('tenant')
                ->table($table)
                ->whereNull('site_id')
                ->update(['site_id' => $site->id]);
        }
    }

    public static function backfillLegacyCentralSite(?Site $site, array $tables): void
    {
        if (!$site) {
            return;
        }

        self::ensureCentralSiteColumns($tables);

        foreach ($tables as $table) {
            if (!Schema::connection('central')->hasTable($table) || !Schema::connection('central')->hasColumn($table, 'site_id')) {
                continue;
            }

            DB::connection('central')
                ->table($table)
                ->where('tenant_id', $site->tenant_id)
                ->whereNull('site_id')
                ->update(['site_id' => $site->id]);
        }
    }

    public static function ensureTenantSiteColumns(array $tables): void
    {
        self::ensureSiteColumns('tenant', $tables);
    }

    public static function ensureCentralSiteColumns(array $tables): void
    {
        self::ensureSiteColumns('central', $tables);
    }

    private static function ensureSiteColumns(string $connection, array $tables): void
    {
        foreach ($tables as $table) {
            if (!Schema::connection($connection)->hasTable($table) || Schema::connection($connection)->hasColumn($table, 'site_id')) {
                continue;
            }

            Schema::connection($connection)->table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('site_id')->nullable()->index();
            });
        }
    }

    public static function tenantId(): ?int
    {
        return app()->bound('tenant') ? app('tenant')->id : null;
    }

    public static function applyToTenantTable(Builder $query, string $table, ?Site $site, ?string $siteNameColumn = null): Builder
    {
        if ($site && Schema::connection('tenant')->hasColumn($table, 'site_id')) {
            return $query->where("{$table}.site_id", $site->id);
        }

        if ($site && $siteNameColumn && Schema::connection('tenant')->hasColumn($table, $siteNameColumn)) {
            return $query->where("{$table}.{$siteNameColumn}", $site->name);
        }

        if ($site) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function tenantCompatColumns(string $table, array $data): array
    {
        if (Schema::connection('tenant')->hasColumn($table, 'tenant_id') && self::tenantId()) {
            $data['tenant_id'] = self::tenantId();
        }

        return $data;
    }

    public static function withSiteColumn(string $table, array $data, ?Site $site): array
    {
        if ($site && Schema::connection('tenant')->hasColumn($table, 'site_id')) {
            $data['site_id'] = $site->id;
        }

        return $data;
    }
}
