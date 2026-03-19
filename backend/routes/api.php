<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\RadiusController;
use App\Http\Controllers\SuperAdminAuthController;
use App\Http\Controllers\AdminTenantController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\SystemSettingController;
use App\Http\Controllers\TenantDashboardController;
use App\Http\Controllers\PlatformFeeController;

Route::post('/super-admin/login', [SuperAdminAuthController::class, 'login']);

Route::get('/system/settings/public', [SystemSettingController::class, 'publicSettings']);

Route::prefix('tenant/signup')->group(function () {
    Route::post('/', [TenantController::class, 'store']);
});

Route::middleware(['auth:sanctum'])->prefix('super-admin')->group(function () {
    Route::post('/logout', [SuperAdminAuthController::class, 'logout']);
    Route::get('/me', [SuperAdminAuthController::class, 'me']);
    Route::post('/change-password', [SuperAdminAuthController::class, 'changePassword']);

    Route::prefix('tenants')->group(function () {
        Route::get('/', [TenantController::class, 'index']);
        Route::get('/pending', [AdminTenantController::class, 'pending']);
        Route::get('/statistics', [AdminTenantController::class, 'statistics']);
        Route::get('/recent-activity', [AdminTenantController::class, 'recentActivity']);
        Route::get('/{tenant}', [TenantController::class, 'show']);
        Route::put('/{tenant}', [TenantController::class, 'update']);
        Route::delete('/{tenant}', [TenantController::class, 'destroy']);
        Route::post('/{tenant}/approve', [AdminTenantController::class, 'approve']);
        Route::post('/{tenant}/reject', [AdminTenantController::class, 'reject']);
        Route::post('/{tenant}/suspend', [TenantController::class, 'suspend']);
        Route::post('/{tenant}/activate', [TenantController::class, 'activate']);
        Route::post('/{tenant}/regenerate-credentials', [TenantController::class, 'regenerateCredentials']);
        Route::post('/{tenant}/extend-trial', [TenantController::class, 'extendTrial']);
        Route::post('/{tenant}/subscribe', [TenantController::class, 'subscribe']);
        Route::get('/{tenant}/stats', [TenantController::class, 'stats']);
        Route::get('/{tenant}/database', [AdminTenantController::class, 'viewDatabase']);
        Route::post('/{tenant}/database/query', [AdminTenantController::class, 'queryDatabase']);
    });

    Route::prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index']);
        Route::post('/', [AnnouncementController::class, 'store']);
        Route::get('/{announcement}', [AnnouncementController::class, 'show']);
        Route::put('/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('/{announcement}', [AnnouncementController::class, 'destroy']);
    });

    Route::prefix('settings')->group(function () {
        Route::get('/', [SystemSettingController::class, 'index']);
        Route::get('/groups', [SystemSettingController::class, 'groups']);
        Route::get('/group/{group}', [SystemSettingController::class, 'byGroup']);
        Route::post('/', [SystemSettingController::class, 'store']);
        Route::get('/{key}', [SystemSettingController::class, 'show']);
        Route::put('/{key}', [SystemSettingController::class, 'update']);
        Route::delete('/{key}', [SystemSettingController::class, 'destroy']);
        Route::post('/bulk-update', [SystemSettingController::class, 'bulkUpdate']);
    });

    // Platform Fee Management
    Route::prefix('platform-fees')->group(function () {
        Route::get('/settings', [PlatformFeeController::class, 'getSettings']);
        Route::put('/settings', [PlatformFeeController::class, 'updateSettings']);
        Route::get('/revenue', [PlatformFeeController::class, 'getRevenueSummary']);
        Route::get('/records', [PlatformFeeController::class, 'getFeeRecords']);
        Route::get('/tenant-balances', [PlatformFeeController::class, 'getTenantBalances']);
    });
});

Route::middleware(['tenant'])->group(function () {
    // Tenant Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/stats', [TenantDashboardController::class, 'getRealtimeStats']);
        Route::get('/realtime', [TenantDashboardController::class, 'getRealtimeStats']);
        Route::get('/active-users', [TenantDashboardController::class, 'getActiveUsers']);
        Route::get('/router-script', [TenantDashboardController::class, 'getRouterScript']);
    });

    Route::prefix('payments')->group(function () {
        Route::post('/initiate', [PaymentController::class, 'initiate']);
        Route::get('/check-status', [PaymentController::class, 'checkStatus']);
        Route::post('/ipn', [PaymentController::class, 'ipn']);
        Route::post('/failure', [PaymentController::class, 'failure']);
    });

    Route::prefix('vouchers')->group(function () {
        Route::get('/', [VoucherController::class, 'index']);
        Route::get('/{id}', [VoucherController::class, 'show']);
        Route::post('/generate-batch', [VoucherController::class, 'generateBatch']);
        Route::post('/validate', [VoucherController::class, 'validate']);
        Route::get('/statistics', [VoucherController::class, 'statistics']);
        Route::get('/types', [VoucherController::class, 'getTypes']);
        Route::get('/groups', [VoucherController::class, 'getGroups']);
    });

    Route::prefix('routers')->group(function () {
        Route::get('/', [MikrotikController::class, 'index']);
        Route::post('/', [MikrotikController::class, 'store']);
        Route::get('/{id}', [MikrotikController::class, 'show']);
        Route::put('/{id}', [MikrotikController::class, 'update']);
        Route::delete('/{id}', [MikrotikController::class, 'destroy']);
        Route::post('/{id}/test-connection', [MikrotikController::class, 'testConnection']);
        Route::get('/{id}/active-users', [MikrotikController::class, 'getActiveUsers']);
        Route::post('/{id}/collect-telemetry', [MikrotikController::class, 'collectTelemetry']);
        Route::post('/telemetry/ingest', [MikrotikController::class, 'ingestTelemetry']);
    });

    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/{id}', [TransactionController::class, 'show']);
        Route::get('/statistics', [TransactionController::class, 'statistics']);
        Route::get('/daily-report', [TransactionController::class, 'dailyReport']);
    });

    Route::prefix('radius')->group(function () {
        Route::post('/authenticate', [RadiusController::class, 'authenticate']);
        Route::post('/sync-voucher', [RadiusController::class, 'syncVoucher']);
        Route::post('/remove-voucher', [RadiusController::class, 'removeVoucher']);
    });
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'timezone' => config('app.timezone'),
    ]);
});
