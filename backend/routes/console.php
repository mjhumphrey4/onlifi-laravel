<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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

Schedule::command('onlifi:vouchers:cleanup')
    ->everyMinute()
    ->withoutOverlapping();
