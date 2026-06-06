<?php

use App\Http\Controllers\Api\ManualAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'project' => 'payments-manual',
    'timestamp' => now()->toIso8601String(),
]));

Route::post('/auth/login', [ManualAdminController::class, 'login']);

Route::middleware('manual.admin')->group(function () {
    Route::get('/me', [ManualAdminController::class, 'me']);
    Route::get('/dashboard', [ManualAdminController::class, 'dashboard']);
    Route::get('/transactions', [ManualAdminController::class, 'transactions']);

    Route::get('/providers', [ManualAdminController::class, 'providers']);
    Route::post('/providers', [ManualAdminController::class, 'storeProvider']);
    Route::put('/providers/{provider}', [ManualAdminController::class, 'updateProvider']);
    Route::delete('/providers/{provider}', [ManualAdminController::class, 'destroyProvider']);

    Route::get('/callbacks', [ManualAdminController::class, 'callbacks']);
    Route::post('/callbacks', [ManualAdminController::class, 'storeCallback']);
    Route::put('/callbacks/{callback}', [ManualAdminController::class, 'updateCallback']);
    Route::delete('/callbacks/{callback}', [ManualAdminController::class, 'destroyCallback']);

    Route::get('/withdrawal-apis', [ManualAdminController::class, 'withdrawalApis']);
    Route::post('/withdrawal-apis', [ManualAdminController::class, 'storeWithdrawalApi']);
    Route::put('/withdrawal-apis/{withdrawalApi}', [ManualAdminController::class, 'updateWithdrawalApi']);
    Route::delete('/withdrawal-apis/{withdrawalApi}', [ManualAdminController::class, 'destroyWithdrawalApi']);

    Route::get('/settings', [ManualAdminController::class, 'settings']);
    Route::put('/settings', [ManualAdminController::class, 'updateSettings']);
    Route::post('/settings/test-legacy-db', [ManualAdminController::class, 'testLegacyDatabase']);
    Route::get('/audit-logs', [ManualAdminController::class, 'auditLogs']);
});
