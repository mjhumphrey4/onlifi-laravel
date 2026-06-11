<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('onlifi:vouchers:cleanup {--no-kick : Reconcile accounting without disconnecting active MikroTik sessions}', function () {
    $result = app(\App\Services\VoucherAccountingService::class)
        ->cleanupAllTenants(!$this->option('no-kick'));

    $this->info('Voucher accounting cleanup completed.');
    $this->table(
        ['Tenants', 'Sites', 'Reconciled', 'Expired', 'Kick Attempts', 'Failed'],
        [[
            $result['tenants'] ?? 0,
            $result['sites'] ?? 0,
            $result['reconciled'] ?? 0,
            $result['expired'] ?? 0,
            $result['kicked'] ?? 0,
            $result['failed'] ?? 0,
        ]]
    );
})->purpose('Reconcile voucher first login, usage, expiry, RADIUS cleanup, and MikroTik disconnects');

Artisan::command('onlifi:radius:sync-active {--router= : NAS-Identifier/router identity to sync} {--tenant= : Tenant ID to sync} {--site= : Site ID to sync} {--voucher= : Single voucher code to sync} {--backfill-site : Assign null voucher site_id values to the selected site before syncing}', function () {
    $routerIdentifier = trim((string) $this->option('router'));
    $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
    $siteId = $this->option('site') ? (int) $this->option('site') : null;
    $voucherCode = trim((string) $this->option('voucher'));
    $backfillSite = (bool) $this->option('backfill-site');
    $targets = collect();

    if ($routerIdentifier !== '') {
        $nas = DB::connection('central')
            ->table('nas')
            ->where('router_identifier', $routerIdentifier)
            ->first();

        if (!$nas) {
            $this->error("No NAS/router found for {$routerIdentifier}.");
            return Command::FAILURE;
        }

        $tenantId = (int) $nas->tenant_id;
        $siteId = Schema::connection('central')->hasColumn('nas', 'site_id') && !empty($nas->site_id)
            ? (int) $nas->site_id
            : $siteId;
    }

    if ($tenantId) {
        $tenant = \App\Models\Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant {$tenantId} was not found.");
            return Command::FAILURE;
        }

        $site = $siteId
            ? \App\Models\Site::where('tenant_id', $tenant->id)->where('id', $siteId)->first()
            : null;

        if ($siteId && !$site) {
            $this->error("Site {$siteId} was not found for tenant {$tenant->id}.");
            return Command::FAILURE;
        }

        $targets->push([$tenant, $site]);
    } else {
        \App\Models\Tenant::whereNotNull('database_name')->orderBy('id')->chunkById(25, function ($tenants) use ($targets) {
            foreach ($tenants as $tenant) {
                $sites = \App\Models\Site::where('tenant_id', $tenant->id)->get();
                if ($sites->isEmpty()) {
                    $targets->push([$tenant, null]);
                    continue;
                }

                foreach ($sites as $site) {
                    $targets->push([$tenant, $site]);
                }
            }
        });
    }

    $rows = [];
    $totalSynced = 0;
    $totalFailed = 0;

    foreach ($targets as [$tenant, $site]) {
        try {
            if ($site) {
                $site->configureTenantConnection($tenant);
            } else {
                $tenant->configure();
            }

            if (!Schema::connection('tenant')->hasTable('vouchers') || !Schema::connection('tenant')->hasTable('radcheck') || !Schema::connection('tenant')->hasTable('radreply')) {
                $rows[] = [$tenant->id, $site?->id ?: '-', 0, 0, 'missing RADIUS tables'];
                continue;
            }

            if ($site && $backfillSite && Schema::connection('tenant')->hasColumn('vouchers', 'site_id')) {
                $backfillQuery = DB::connection('tenant')->table('vouchers')->whereNull('site_id');
                if ($voucherCode !== '') {
                    $backfillQuery->where('voucher_code', $voucherCode);
                }
                $backfillQuery->update(['site_id' => $site->id]);
            }

            $query = \App\Models\Voucher::whereIn('status', ['unused', 'reserved', 'in_use'])
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                });

            if ($site && Schema::connection('tenant')->hasColumn('vouchers', 'site_id')) {
                $query->where('site_id', $site->id);
            }

            if ($voucherCode !== '') {
                $query->where('voucher_code', $voucherCode);
            }

            $vouchers = $query->get();
            if ($voucherCode !== '' && $vouchers->isEmpty()) {
                $totalFailed++;
                $rows[] = [$tenant->id, $site?->id ?: '-', 0, 0, "voucher {$voucherCode} not found for selected site/db"];
                continue;
            }

            $result = app(\App\Services\FreeRadiusService::class)->syncBatchToRadius($vouchers);
            $totalSynced += $result['synced'] ?? 0;
            $totalFailed += $result['failed'] ?? 0;
            $rows[] = [$tenant->id, $site?->id ?: '-', $vouchers->count(), $result['synced'] ?? 0, $result['failed'] ? 'failed' : 'ok'];
        } catch (\Throwable $e) {
            $totalFailed++;
            $rows[] = [$tenant->id, $site?->id ?: '-', 0, 0, $e->getMessage()];
        }
    }

    $this->table(['Tenant', 'Site', 'Active Vouchers', 'Synced', 'Status'], $rows);
    $this->info("RADIUS sync finished. Synced: {$totalSynced}; Failed: {$totalFailed}");

    return $totalFailed > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Repair/sync active vouchers into tenant radcheck/radreply tables');

Artisan::command('onlifi:radius:diagnose {--router= : NAS-Identifier/router identity from FreeRADIUS logs} {--voucher= : Voucher code from FreeRADIUS User-Name}', function () {
    $routerIdentifier = trim((string) $this->option('router'));
    $voucherCode = trim((string) $this->option('voucher'));

    if ($routerIdentifier === '' || $voucherCode === '') {
        $this->error('Both --router and --voucher are required.');
        $this->line('Example: php artisan onlifi:radius:diagnose --router=main-router22-ONLIFI-1 --voucher=136485');
        return Command::FAILURE;
    }

    $nas = DB::connection('central')
        ->table('nas')
        ->where('router_identifier', $routerIdentifier)
        ->first();

    if (!$nas) {
        $this->error("No NAS/router found for {$routerIdentifier}.");
        return Command::FAILURE;
    }

    $tenant = \App\Models\Tenant::find((int) $nas->tenant_id);
    if (!$tenant) {
        $this->error("NAS {$routerIdentifier} points to missing tenant {$nas->tenant_id}.");
        return Command::FAILURE;
    }

    $siteId = Schema::connection('central')->hasColumn('nas', 'site_id') && !empty($nas->site_id)
        ? (int) $nas->site_id
        : null;
    $site = $siteId
        ? \App\Models\Site::where('tenant_id', $tenant->id)->where('id', $siteId)->first()
        : null;

    if ($siteId && !$site) {
        $this->error("NAS {$routerIdentifier} points to missing site {$siteId} for tenant {$tenant->id}.");
        return Command::FAILURE;
    }

    if ($site) {
        $site->configureTenantConnection($tenant);
    } else {
        $tenant->configure();
    }

    $this->table(['Router', 'Tenant', 'Tenant DB', 'Site', 'Site DB'], [[
        $routerIdentifier,
        "{$tenant->id} / {$tenant->name}",
        $tenant->database_name,
        $site ? "{$site->id} / {$site->name}" : '-',
        $site?->database_name ?: $tenant->database_name,
    ]]);

    if (!Schema::connection('tenant')->hasTable('vouchers') || !Schema::connection('tenant')->hasTable('radcheck') || !Schema::connection('tenant')->hasTable('radreply')) {
        $this->error('The selected tenant/site database is missing vouchers, radcheck, or radreply tables.');
        return Command::FAILURE;
    }

    $voucher = DB::connection('tenant')
        ->table('vouchers')
        ->where('voucher_code', $voucherCode)
        ->first();
    $radcheck = DB::connection('tenant')
        ->table('radcheck')
        ->where('username', $voucherCode)
        ->where('attribute', 'Cleartext-Password')
        ->first();
    $radreply = DB::connection('tenant')
        ->table('radreply')
        ->where('username', $voucherCode)
        ->orderBy('attribute')
        ->get(['attribute', 'op', 'value']);
    $oldestAccounting = Schema::connection('tenant')->hasTable('radacct')
        ? DB::connection('tenant')
            ->table('radacct')
            ->where('username', $voucherCode)
            ->orderBy('acctstarttime')
            ->first(['acctstarttime', 'acctstoptime', 'acctsessiontime', 'acctterminatecause'])
        : null;

    $validityHours = $voucher ? (int) ($voucher->validity_hours ?? 0) : 0;
    $validityMinutes = $voucher ? (int) ($voucher->validity_minutes ?? 0) : 0;
    $effectiveMinutes = max($validityMinutes, $validityHours * 60, 1);
    $expectedExpiresAt = null;

    if ($voucher && !empty($voucher->first_used_at)) {
        $expectedExpiresAt = \Illuminate\Support\Carbon::parse($voucher->first_used_at)
            ->addMinutes($effectiveMinutes)
            ->toDateTimeString();
    }

    $this->table(['Check', 'Result'], [
        ['Voucher row', $voucher ? 'found' : 'missing'],
        ['Voucher status', $voucher->status ?? '-'],
        ['Voucher site_id', $voucher->site_id ?? 'NULL'],
        ['Expected site_id', $site?->id ?? 'NULL'],
        ['Validity hours', $voucher->validity_hours ?? '-'],
        ['Validity minutes', $voucher->validity_minutes ?? 'NULL'],
        ['Effective minutes', $voucher ? (string) $effectiveMinutes : '-'],
        ['First used at', $voucher->first_used_at ?? 'NULL'],
        ['Current expires_at', $voucher->expires_at ?? 'NULL'],
        ['Expected expires_at', $expectedExpiresAt ?? 'NULL'],
        ['Expired reason', $voucher->expired_reason ?? 'NULL'],
        ['Oldest radacct start', $oldestAccounting->acctstarttime ?? 'NULL'],
        ['Oldest radacct stop', $oldestAccounting->acctstoptime ?? 'NULL'],
        ['radcheck password', $radcheck ? ($radcheck->value === $voucherCode ? 'found; equals voucher code' : 'found; different from voucher code') : 'missing'],
        ['radreply rows', (string) $radreply->count()],
    ]);

    if ($voucher && $validityHours > 0 && $validityMinutes > 0 && $validityMinutes < ($validityHours * 60)) {
        $this->warn("Voucher validity_minutes is shorter than validity_hours. It should be at least " . ($validityHours * 60) . " for {$validityHours}h.");
    }

    if ($voucher && $expectedExpiresAt && !empty($voucher->expires_at) && \Illuminate\Support\Carbon::parse($voucher->expires_at)->lt(\Illuminate\Support\Carbon::parse($expectedExpiresAt))) {
        $this->warn("Voucher expires_at is earlier than the expected expiry. Run tenants migration and radius sync, then copy/restart FreeRADIUS Perl module.");
    }

    if ($radreply->isNotEmpty()) {
        $this->table(['Attribute', 'Op', 'Value'], $radreply->map(fn ($row) => [
            $row->attribute,
            $row->op,
            $row->value,
        ])->all());
    }

    if (!$voucher || !$radcheck) {
        $this->warn('Repair with: php artisan onlifi:radius:sync-active --router=' . $routerIdentifier . ' --voucher=' . $voucherCode . ' --backfill-site');
    }

    $this->line("Direct test:");
    $this->line("echo 'User-Name={$voucherCode},User-Password={$voucherCode},NAS-Identifier={$routerIdentifier}' | radclient -x 127.0.0.1 auth " . config('radius.shared_secret', 'Onlifi26A'));

    return (!$voucher || !$radcheck) ? Command::FAILURE : Command::SUCCESS;
})->purpose('Diagnose one router/voucher RADIUS lookup across tenant/site DB and radcheck');

Artisan::command('onlifi:tenants:migrate', function () {
    $rows = [];
    $failed = 0;

    \App\Models\Tenant::whereNotNull('database_name')->orderBy('id')->chunkById(25, function ($tenants) use (&$rows, &$failed) {
        foreach ($tenants as $tenant) {
            try {
                $tenant->configure();
                Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);
                $rows[] = [$tenant->id, '-', $tenant->database_name, 'ok'];
            } catch (\Throwable $e) {
                $failed++;
                $rows[] = [$tenant->id, '-', $tenant->database_name, $e->getMessage()];
            }

            foreach (\App\Models\Site::where('tenant_id', $tenant->id)->whereNotNull('database_name')->orderBy('id')->get() as $site) {
                try {
                    $site->configureTenantConnection($tenant);
                    Artisan::call('migrate', [
                        '--database' => 'tenant',
                        '--path' => 'database/migrations/tenant',
                        '--force' => true,
                    ]);
                    $rows[] = [$tenant->id, $site->id, $site->database_name, 'ok'];
                } catch (\Throwable $e) {
                    $failed++;
                    $rows[] = [$tenant->id, $site->id, $site->database_name, $e->getMessage()];
                }
            }
        }
    });

    $this->table(['Tenant', 'Site', 'Database', 'Status'], $rows);
    $this->info('Tenant/site migration pass completed.');

    return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Run tenant migrations across all tenant and site databases');

Artisan::command('onlifi:router-snapshots:sync {--tenant= : Limit sync to one tenant ID} {--site= : Limit sync to one site ID} {--only= : Comma-separated list: hotspot_users,ip_bindings,system_users,dhcp_leases,dhcp_pools,pppoe_clients}', function () {
    $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
    $siteId = $this->option('site') ? (int) $this->option('site') : null;
    $only = trim((string) $this->option('only'));
    $types = $only !== '' ? array_values(array_filter(array_map('trim', explode(',', $only)))) : null;
    $rows = [];
    $failed = 0;
    $synced = 0;

    $tenantQuery = \App\Models\Tenant::query()
        ->whereNotNull('database_name')
        ->when($tenantId, fn ($query) => $query->where('id', $tenantId))
        ->orderBy('id');

    $tenantQuery->chunkById(20, function ($tenants) use (&$rows, &$failed, &$synced, $siteId, $types) {
        foreach ($tenants as $tenant) {
            $sites = \App\Models\Site::where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->when($siteId, fn ($query) => $query->where('id', $siteId))
                ->orderBy('id')
                ->get();

            foreach ($sites as $site) {
                $lockKey = "router_snapshot_sync_site_{$site->id}";
                if (!Cache::add($lockKey, true, now()->addMinutes(4))) {
                    $rows[] = [$tenant->id, $site->id, $site->name, 'skipped', 'sync already running'];
                    continue;
                }

                try {
                    if ($site->database_name) {
                        $site->configureTenantConnection($tenant);
                    } else {
                        $tenant->configure();
                    }

                    $result = app(\App\Services\RouterSnapshotService::class)->syncSite($site, $types);
                    $status = ($result['ok'] ?? false) ? 'ok' : 'failed';
                    $summary = json_encode($result['synced'] ?? []);

                    if ($status === 'ok') {
                        $synced++;
                    } else {
                        $failed++;
                    }

                    Cache::put("tenant:{$tenant->id}:site:{$site->id}:router:snapshot:summary", [
                        'ok' => $result['ok'] ?? false,
                        'router' => $result['router'] ?? null,
                        'synced' => $result['synced'] ?? [],
                        'message' => $result['message'] ?? null,
                        'last_synced_at' => now()->toIso8601String(),
                    ], now()->addMinutes(10));

                    $rows[] = [$tenant->id, $site->id, $site->name, $status, $summary ?: ($result['message'] ?? '')];
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('Scheduled router snapshot sync failed', [
                        'tenant_id' => $tenant->id,
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                    $rows[] = [$tenant->id, $site->id, $site->name, 'failed', $e->getMessage()];
                }
            }
        }
    });

    $this->table(['Tenant', 'Site', 'Name', 'Status', 'Details'], $rows);
    $this->info("Router snapshot sync completed. Sites synced: {$synced}; failed: {$failed}");

    return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Pull RouterOS lists into Redis/database snapshots for fast dashboard reads');

Artisan::command('onlifi:pppoe:expire', function () {
    $rows = [];
    $expired = 0;
    $failed = 0;

    \App\Models\Tenant::whereNotNull('database_name')->orderBy('id')->chunkById(20, function ($tenants) use (&$rows, &$expired, &$failed) {
        foreach ($tenants as $tenant) {
            $sites = \App\Models\Site::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('id')->get();

            foreach ($sites as $site) {
                try {
                    $site->configureTenantConnection($tenant);

                    if (!Schema::connection('tenant')->hasTable('pppoe_clients') || !Schema::connection('tenant')->hasColumn('pppoe_clients', 'expires_at')) {
                        continue;
                    }

                    $clients = DB::connection('tenant')
                        ->table('pppoe_clients')
                        ->where('site_id', $site->id)
                        ->where('is_active', true)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now())
                        ->get();

                    if ($clients->isEmpty()) {
                        continue;
                    }

                    $router = app(\App\Services\RouterSnapshotService::class)->routerForSite($site);
                    if (!$router) {
                        $failed += $clients->count();
                        $rows[] = [$tenant->id, $site->id, $clients->count(), 'missing router'];
                        continue;
                    }

                    foreach ($clients as $client) {
                        $ok = true;
                        if ($client->router_id) {
                            $ok = app(\App\Services\MikrotikService::class)->setPppoeSecretDisabled($router, $client->router_id, true);
                        }
                        app(\App\Services\MikrotikService::class)->removeActivePppoeSessions($router, $client->username);

                        if ($ok) {
                            DB::connection('tenant')->table('pppoe_clients')->where('id', $client->id)->update([
                                'is_active' => false,
                                'updated_at' => now(),
                            ]);
                            $expired++;
                        } else {
                            $failed++;
                        }
                    }

                    app(\App\Services\RouterSnapshotService::class)->syncSite($site, ['pppoe_clients']);
                    $rows[] = [$tenant->id, $site->id, $clients->count(), 'ok'];
                } catch (\Throwable $e) {
                    $failed++;
                    $rows[] = [$tenant->id, $site->id, 0, $e->getMessage()];
                }
            }
        }
    });

    $this->table(['Tenant', 'Site', 'Expired', 'Status'], $rows);
    $this->info("PPPoE expiry completed. Disabled: {$expired}; failed: {$failed}");

    return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
})->purpose('Disable expired PPPoE secrets and disconnect active PPPoE sessions');

Schedule::command('onlifi:vouchers:cleanup')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('onlifi:router-snapshots:sync')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('onlifi:pppoe:expire')
    ->everyMinute()
    ->withoutOverlapping();
