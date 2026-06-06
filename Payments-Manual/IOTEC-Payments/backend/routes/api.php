<?php

use App\Http\Controllers\Api\IotecAdminController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'project' => 'iotec-payments',
    'timestamp' => now()->toIso8601String(),
]));

Route::post('/auth/login', [IotecAdminController::class, 'login']);

Route::middleware('iotec.admin')->group(function () {
    Route::get('/me', [IotecAdminController::class, 'me']);
    Route::get('/dashboard', [IotecAdminController::class, 'dashboard']);
    Route::get('/transactions', [IotecAdminController::class, 'transactions']);

    Route::get('/api-profiles', [IotecAdminController::class, 'apiProfiles']);
    Route::post('/api-profiles', [IotecAdminController::class, 'storeApiProfile']);
    Route::put('/api-profiles/{apiProfile}', [IotecAdminController::class, 'updateApiProfile']);
    Route::delete('/api-profiles/{apiProfile}', [IotecAdminController::class, 'destroyApiProfile']);

    Route::get('/callbacks', [IotecAdminController::class, 'callbacks']);
    Route::post('/callbacks', [IotecAdminController::class, 'storeCallback']);
    Route::put('/callbacks/{callback}', [IotecAdminController::class, 'updateCallback']);
    Route::delete('/callbacks/{callback}', [IotecAdminController::class, 'destroyCallback']);

    Route::get('/settings', [IotecAdminController::class, 'settings']);
    Route::put('/settings', [IotecAdminController::class, 'updateSettings']);
    Route::post('/settings/test-legacy-db', [IotecAdminController::class, 'testLegacyDatabase']);
    Route::get('/audit-logs', [IotecAdminController::class, 'auditLogs']);
});
