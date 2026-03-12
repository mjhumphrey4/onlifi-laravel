<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\RadiusController;

Route::prefix('admin/tenants')->group(function () {
    Route::get('/', [TenantController::class, 'index']);
    Route::post('/', [TenantController::class, 'store']);
    Route::get('/{tenant}', [TenantController::class, 'show']);
    Route::put('/{tenant}', [TenantController::class, 'update']);
    Route::delete('/{tenant}', [TenantController::class, 'destroy']);
    Route::post('/{tenant}/suspend', [TenantController::class, 'suspend']);
    Route::post('/{tenant}/activate', [TenantController::class, 'activate']);
    Route::post('/{tenant}/regenerate-credentials', [TenantController::class, 'regenerateCredentials']);
    Route::post('/{tenant}/extend-trial', [TenantController::class, 'extendTrial']);
    Route::post('/{tenant}/subscribe', [TenantController::class, 'subscribe']);
    Route::get('/{tenant}/stats', [TenantController::class, 'stats']);
});

Route::middleware(['tenant'])->group(function () {
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
