<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

            $query = \App\Models\Voucher::whereIn('status', ['unused', 'used'])
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

    $this->table(['Check', 'Result'], [
        ['Voucher row', $voucher ? 'found' : 'missing'],
        ['Voucher status', $voucher->status ?? '-'],
        ['Voucher site_id', $voucher->site_id ?? 'NULL'],
        ['Expected site_id', $site?->id ?? 'NULL'],
        ['radcheck password', $radcheck ? ($radcheck->value === $voucherCode ? 'found; equals voucher code' : 'found; different from voucher code') : 'missing'],
        ['radreply rows', (string) $radreply->count()],
    ]);

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
    $this->line("echo 'User-Name={$voucherCode},User-Password={$voucherCode},NAS-Identifier={$routerIdentifier}' | radclient -x 127.0.0.1 auth onlifi_radius_secret_change_me");

    return (!$voucher || !$radcheck) ? Command::FAILURE : Command::SUCCESS;
})->purpose('Diagnose one router/voucher RADIUS lookup across tenant/site DB and radcheck');

Schedule::command('onlifi:vouchers:cleanup')
    ->everyMinute()
    ->withoutOverlapping();
