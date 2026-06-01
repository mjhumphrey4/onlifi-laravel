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
use App\Http\Controllers\SalesPointController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\TenantAuthController;
use App\Http\Controllers\TelemetryController;
use App\Http\Controllers\RadiusAccountingController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\SubscriptionBillingController;
use App\Http\Controllers\CaptivePortalController;
use App\Http\Controllers\SmsCreditController;
use App\Http\Controllers\RemoteAccessController;
use App\Http\Controllers\PppoeClientController;
use App\Http\Controllers\SupportTicketController;

Route::post('/super-admin/login', [SuperAdminAuthController::class, 'login']);

// Tenant Authentication Routes
Route::post('/tenant/login', [TenantAuthController::class, 'login']);
Route::post('/tenant/forgot-password', [TenantAuthController::class, 'forgotPassword']);
Route::post('/tenant/reset-password', [TenantAuthController::class, 'resetPassword']);

Route::get('/system/settings/public', [SystemSettingController::class, 'publicSettings']);
Route::post('/billing/ipn', [SubscriptionBillingController::class, 'ipn']);
Route::post('/billing/failure', [SubscriptionBillingController::class, 'failure']);
Route::get('/captive/config/{token}', [CaptivePortalController::class, 'config']);
Route::get('/captive/hotspot/{token}/{file}', [CaptivePortalController::class, 'hotspotFile'])
    ->where('file', 'login\.html|status\.html|alogin\.html|md5\.js');
Route::post('/captive/pay', [CaptivePortalController::class, 'pay']);
Route::get('/captive/payment-status', [CaptivePortalController::class, 'paymentStatus']);
Route::post('/captive/ipn', [CaptivePortalController::class, 'ipn']);
Route::post('/captive/failure', [CaptivePortalController::class, 'failure']);
Route::post('/sms-credits/ipn', [SmsCreditController::class, 'ipn']);
Route::post('/sms-credits/failure', [SmsCreditController::class, 'failure']);

// Public telemetry endpoint for routers (authenticated via API token in request)
Route::post('/telemetry', [TelemetryController::class, 'receive']);
Route::get('/router/provision/{token}', [\App\Http\Controllers\NasController::class, 'publicProvisioningScript']);
Route::get('/router/telemetry/{token}', [\App\Http\Controllers\NasController::class, 'publicTelemetryScript']);

// Telemetry data endpoints (authenticated)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/telemetry/latest', [TelemetryController::class, 'getLatest']);
    Route::get('/telemetry/stats', [TelemetryController::class, 'getStats']);
    Route::get('/telemetry/usage', [TelemetryController::class, 'getUsage']);
});

Route::prefix('tenant/signup')->group(function () {
    Route::post('/', [TenantController::class, 'store']);
});

Route::middleware(['auth:sanctum'])->prefix('super-admin')->group(function () {
    Route::post('/logout', [SuperAdminAuthController::class, 'logout']);
    Route::get('/me', [SuperAdminAuthController::class, 'me']);
    Route::post('/change-password', [SuperAdminAuthController::class, 'changePassword']);
    Route::get('/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);

    Route::prefix('tenants')->group(function () {
        Route::get('/', [TenantController::class, 'index']);
        Route::get('/pending', [AdminTenantController::class, 'pending']);
        Route::get('/statistics', [AdminTenantController::class, 'statistics']);
        Route::get('/recent-activity', [AdminTenantController::class, 'recentActivity']);
        Route::get('/{tenant}', [TenantController::class, 'show']);
        Route::put('/{tenant}', [AdminTenantController::class, 'updateTenant']);
        Route::delete('/{tenant}', [TenantController::class, 'destroy']);
        Route::post('/{tenant}/approve', [AdminTenantController::class, 'approve']);
        Route::post('/{tenant}/reject', [AdminTenantController::class, 'reject']);
        Route::post('/{tenant}/suspend', [TenantController::class, 'suspend']);
        Route::post('/{tenant}/activate', [TenantController::class, 'activate']);
        Route::post('/{tenant}/extend-trial', [TenantController::class, 'extendTrial']);
        Route::post('/{tenant}/reset-password', [AdminTenantController::class, 'resetPassword']);
        Route::post('/{tenant}/repair', [AdminTenantController::class, 'repairTenant']);
        Route::post('/{tenant}/sms-credits/adjust', [AdminTenantController::class, 'adjustSmsCredits']);
        Route::get('/{tenant}/remote-access', [RemoteAccessController::class, 'adminIndex']);
        Route::put('/{tenant}/remote-access/{site}', [RemoteAccessController::class, 'adminUpdate']);
        Route::post('/{tenant}/regenerate-credentials', [TenantController::class, 'regenerateCredentials']);
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

    Route::prefix('support-tickets')->group(function () {
        Route::get('/', [SupportTicketController::class, 'adminIndex']);
        Route::get('/notifications', [SupportTicketController::class, 'adminNotifications']);
        Route::get('/{id}', [SupportTicketController::class, 'adminShow']);
        Route::post('/{id}/reply', [SupportTicketController::class, 'adminReply']);
        Route::put('/{id}/status', [SupportTicketController::class, 'adminUpdateStatus']);
    });
});

// Tenant authenticated routes
Route::middleware(['auth:sanctum'])->prefix('tenant')->group(function () {
    Route::post('/logout', [TenantAuthController::class, 'logout']);
    Route::get('/me', [TenantAuthController::class, 'me']);
    Route::post('/change-password', [TenantAuthController::class, 'changePassword']);
    Route::get('/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('/2fa/disable', [TwoFactorController::class, 'disable']);
    Route::get('/billing/status', [SubscriptionBillingController::class, 'status']);
    Route::post('/billing/subscribe', [SubscriptionBillingController::class, 'subscribe']);
    Route::get('/billing/payment-status', [SubscriptionBillingController::class, 'paymentStatus']);
    Route::get('/captive-portal/templates', [CaptivePortalController::class, 'templates']);
    Route::post('/captive-portal/templates', [CaptivePortalController::class, 'saveTemplate']);
    Route::post('/captive-portal/preview', [CaptivePortalController::class, 'preview']);
    Route::post('/captive-portal/download', [CaptivePortalController::class, 'download']);
    Route::post('/captive-portal/logo', [CaptivePortalController::class, 'uploadLogo']);
    Route::post('/captive-portal/templates/{template}/activate', [CaptivePortalController::class, 'activateTemplate']);
    Route::get('/sms-credits', [SmsCreditController::class, 'summary']);
    Route::put('/sms-credits/plan', [SmsCreditController::class, 'updatePlan']);
    Route::post('/sms-credits/top-up', [SmsCreditController::class, 'topUp']);
    Route::get('/sms-credits/payment-status', [SmsCreditController::class, 'paymentStatus']);
    Route::get('/remote-access', [RemoteAccessController::class, 'tenantIndex']);

    Route::prefix('support-tickets')->group(function () {
        Route::get('/', [SupportTicketController::class, 'tenantIndex']);
        Route::post('/', [SupportTicketController::class, 'tenantStore']);
        Route::get('/notifications', [SupportTicketController::class, 'tenantNotifications']);
        Route::get('/{id}', [SupportTicketController::class, 'tenantShow']);
        Route::put('/{id}', [SupportTicketController::class, 'tenantUpdate']);
        Route::post('/{id}/reply', [SupportTicketController::class, 'tenantReply']);
    });
});

// Active announcements for all authenticated users
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/announcements/active', [AnnouncementController::class, 'activeAnnouncements']);
    
    // NAS (Network Access Server) management for FreeRADIUS
    // Uses central database, not tenant database
    Route::prefix('nas')->group(function () {
        Route::get('/', [\App\Http\Controllers\NasController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\NasController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\NasController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\NasController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\NasController::class, 'destroy']);
        Route::post('/{id}/regenerate-identifier', [\App\Http\Controllers\NasController::class, 'regenerateIdentifier']);
        Route::get('/{id}/mikrotik-script', [\App\Http\Controllers\NasController::class, 'getMikrotikScript']);
    });
});

Route::middleware(['tenant'])->group(function () {
    // Clients
    Route::prefix('clients')->group(function () {
        Route::get('/', [\App\Http\Controllers\ClientController::class, 'index']);
        Route::get('/refresh', [\App\Http\Controllers\ClientController::class, 'refresh']);
        Route::get('/{id}', [\App\Http\Controllers\ClientController::class, 'show']);
    });

    // Tenant Dashboard
    Route::middleware('tenant.billing')->prefix('dashboard')->group(function () {
        Route::get('/stats', [TenantDashboardController::class, 'getRealtimeStats']);
        Route::get('/realtime', [TenantDashboardController::class, 'getRealtimeStats']);
        Route::get('/active-users', [TenantDashboardController::class, 'getActiveUsers']);
        Route::get('/router-script', [TenantDashboardController::class, 'getRouterScript']);
    });

    // RADIUS Accounting endpoints - active users from radacct table
    Route::prefix('radius')->group(function () {
        Route::get('/active-users', [RadiusAccountingController::class, 'getActiveUsers']);
        Route::get('/users/{username}/history', [RadiusAccountingController::class, 'getUserHistory']);
        Route::get('/accounting/stats', [RadiusAccountingController::class, 'getStats']);
    });

    Route::prefix('payments')->group(function () {
        Route::post('/initiate', [PaymentController::class, 'initiate']);
        Route::get('/check-status', [PaymentController::class, 'checkStatus']);
        Route::post('/ipn', [PaymentController::class, 'ipn']);
        Route::post('/failure', [PaymentController::class, 'failure']);
    });

    Route::prefix('vouchers')->group(function () {
        Route::get('/', [VoucherController::class, 'index']);
        Route::get('/statistics', [VoucherController::class, 'statistics']);
        Route::get('/types', [VoucherController::class, 'getTypes']);
        Route::post('/types', [VoucherController::class, 'storeType']);
        Route::put('/types/{id}', [VoucherController::class, 'updateType']);
        Route::delete('/types/{id}', [VoucherController::class, 'destroyType']);
        Route::get('/groups', [VoucherController::class, 'getGroups']);
        Route::post('/generate-batch', [VoucherController::class, 'generateBatch']);
        Route::post('/validate', [VoucherController::class, 'validate']);
        Route::get('/{id}', [VoucherController::class, 'show']);
    });

    // Voucher Templates
    Route::prefix('voucher-templates')->group(function () {
        Route::get('/', [\App\Http\Controllers\VoucherTemplateController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\VoucherTemplateController::class, 'store']);
        Route::get('/default', [\App\Http\Controllers\VoucherTemplateController::class, 'getDefault']);
        Route::get('/{id}', [\App\Http\Controllers\VoucherTemplateController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\VoucherTemplateController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\VoucherTemplateController::class, 'destroy']);
        Route::post('/{id}/set-default', [\App\Http\Controllers\VoucherTemplateController::class, 'setDefault']);
    });

    Route::prefix('routers')->group(function () {
        Route::get('/', [MikrotikController::class, 'index']);
        Route::post('/', [MikrotikController::class, 'store']);
        Route::get('/ip-bindings', [MikrotikController::class, 'getIpBindings']);
        Route::post('/ip-bindings', [MikrotikController::class, 'addIpBinding']);
        Route::get('/system-users', [MikrotikController::class, 'getSystemUsers']);
        Route::post('/system-users', [MikrotikController::class, 'addSystemUser']);
        Route::post('/system-users/status', [MikrotikController::class, 'updateSystemUserStatus']);
        Route::get('/{id}', [MikrotikController::class, 'show']);
        Route::put('/{id}', [MikrotikController::class, 'update']);
        Route::delete('/{id}', [MikrotikController::class, 'destroy']);
        Route::post('/{id}/test-connection', [MikrotikController::class, 'testConnection']);
        Route::get('/{id}/active-users', [MikrotikController::class, 'getActiveUsers']);
        Route::get('/{id}/telemetry/latest', [MikrotikController::class, 'getRealtimeStats']);
        Route::post('/{id}/collect-telemetry', [MikrotikController::class, 'collectTelemetry']);
        Route::post('/telemetry/ingest', [MikrotikController::class, 'ingestTelemetry']);
    });

    Route::prefix('pppoe')->group(function () {
        Route::get('/clients', [PppoeClientController::class, 'index']);
        Route::post('/clients', [PppoeClientController::class, 'store']);
        Route::put('/clients/{id}', [PppoeClientController::class, 'update']);
        Route::post('/clients/{id}/enable', [PppoeClientController::class, 'enable']);
        Route::post('/clients/{id}/disable', [PppoeClientController::class, 'disable']);
        Route::delete('/clients/{id}', [PppoeClientController::class, 'destroy']);
    });

    // RADIUS sync endpoints
    Route::prefix('radius')->group(function () {
        Route::post('/sync-vouchers', [\App\Http\Controllers\RadiusController::class, 'syncAllVouchers']);
        Route::post('/sync-voucher/{id}', [\App\Http\Controllers\RadiusController::class, 'syncVoucher']);
        Route::post('/cleanup-expired', [\App\Http\Controllers\RadiusController::class, 'cleanupExpired']);
        Route::get('/sessions/{voucher_code}', [\App\Http\Controllers\RadiusController::class, 'getSessions']);
    });

    Route::prefix('sales-points')->group(function () {
        Route::get('/', [SalesPointController::class, 'index']);
        Route::post('/', [SalesPointController::class, 'store']);
        Route::get('/{id}', [SalesPointController::class, 'show']);
        Route::put('/{id}', [SalesPointController::class, 'update']);
        Route::delete('/{id}', [SalesPointController::class, 'destroy']);
    });

    // Sites - Independent entities for telemetry and voucher management
    Route::prefix('sites')->group(function () {
        Route::get('/', [SiteController::class, 'index']);
        Route::post('/', [SiteController::class, 'store']);
        Route::get('/{id}', [SiteController::class, 'show']);
        Route::put('/{id}', [SiteController::class, 'update']);
        Route::delete('/{id}', [SiteController::class, 'destroy']);
        Route::post('/{id}/regenerate-token', [SiteController::class, 'regenerateToken']);
        Route::get('/{id}/token', [SiteController::class, 'getToken']);
    });

    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/statistics', [TransactionController::class, 'statistics']);
        Route::get('/performance', [TransactionController::class, 'performanceAnalytics']);
        Route::get('/daily-report', [TransactionController::class, 'dailyReport']);
        Route::get('/{id}', [TransactionController::class, 'show']);
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
