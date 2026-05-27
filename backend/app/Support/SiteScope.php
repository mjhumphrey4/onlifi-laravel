<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

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
