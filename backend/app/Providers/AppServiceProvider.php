<?php

namespace App\Providers;

use App\Models\Voucher;
use App\Observers\VoucherObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register Voucher observer for automatic RADIUS sync
        Voucher::observe(VoucherObserver::class);
    }
}
