<?php

namespace App\Services;

use App\Models\PlatformFee;
use App\Models\Site;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\SiteScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CaptivePaymentService
{
    private \YoAPI $yoAPI;

    public function __construct()
    {
        require_once base_path('app/Services/YoAPI.php');

        $this->yoAPI = new \YoAPI(
            config('services.yoapi.username'),
            config('services.yoapi.password'),
            config('services.yoapi.mode', 'sandbox')
        );
    }

    public function initiate(array $data): array
    {
        $nas = DB::connection('central')->table('nas')
            ->where('provisioning_token', $data['token'])
            ->first();

        if (!$nas) {
            return ['status' => -1, 'errorMessage' => 'Router is not registered'];
        }

        $tenant = Tenant::find($nas->tenant_id);
        if (!$tenant || !$tenant->canAccess()) {
            return ['status' => -1, 'errorMessage' => 'Tenant is not active'];
        }

        $site = Schema::connection('central')->hasColumn('nas', 'site_id') && !empty($nas->site_id)
            ? Site::where('tenant_id', $tenant->id)->where('id', $nas->site_id)->first()
            : Site::where('tenant_id', $tenant->id)->where('name', $nas->shortname)->first();
        $siteName = $site?->name ?: $nas->shortname;

        $tenant->configure();
        $packageQuery = DB::connection('tenant')->table('voucher_groups')
            ->where('price', $data['amount']);

        if ($site && Schema::connection('tenant')->hasColumn('voucher_groups', 'site_id')) {
            $packageQuery->where('site_id', $site->id);
        }

        if (!empty($data['voucher_type'])) {
            $packageQuery->where('group_name', $data['voucher_type']);
        }

        $package = $packageQuery->first();
        $hasConfiguredPackages = DB::connection('tenant')->table('voucher_groups')->exists();

        if ($hasConfiguredPackages && !$package) {
            return ['status' => -1, 'errorMessage' => 'Selected WiFi package is not available'];
        }

        $externalRef = sprintf('CAP_%d_%d_%s', $tenant->id, time(), uniqid());
        $msisdn = $this->normalizeMsisdn($data['msisdn']);

        $transactionData = [
            'external_ref' => $externalRef,
            'msisdn' => $msisdn,
            'amount' => $data['amount'],
            'status' => 'pending',
            'origin_site' => $siteName,
            'site_id' => $site?->id,
            'client_mac' => $data['client_mac'] ?? null,
            'email' => $data['email'] ?? null,
            'voucher_type' => $data['voucher_type'] ?? null,
            'origin_url' => $data['origin_url'] ?? null,
        ];

        $transactionData = SiteScope::tenantCompatColumns('transactions', $transactionData);
        if (!Schema::connection('tenant')->hasColumn('transactions', 'site_id')) {
            unset($transactionData['site_id']);
        }

        $transaction = Transaction::create($transactionData);

        $this->yoAPI->set_external_reference($externalRef);
        $this->yoAPI->set_nonblocking('TRUE');
        $apiBaseUrl = rtrim((string) SystemSetting::get('api_base_url', config('app.api_url', config('app.url'))), '/');
        $this->yoAPI->set_instant_notification_url($apiBaseUrl . '/api/captive/ipn');
        $this->yoAPI->set_failure_notification_url($apiBaseUrl . '/api/captive/failure');

        $response = $this->yoAPI->ac_deposit_funds(
            $msisdn,
            $data['amount'],
            'OnLiFi WiFi Voucher - ' . $tenant->name
        );

        if (($response['Status'] ?? null) === 'OK') {
            $transaction->update([
                'transaction_ref' => $response['TransactionReference'] ?? null,
                'status_message' => $response['StatusMessage'] ?? 'Payment initiated',
            ]);

            return [
                'status' => 1,
                'message' => 'Payment initiated. Confirm the prompt on your phone.',
                'externalReference' => $externalRef,
                'transactionReference' => $response['TransactionReference'] ?? null,
            ];
        }

        $message = $response['StatusMessage'] ?? 'Mobile money initiation failed';
        $transaction->update(['status' => 'failed', 'status_message' => $message]);

        return ['status' => -1, 'errorMessage' => $message, 'externalReference' => $externalRef];
    }

    public function status(string $reference): array
    {
        $tenantId = $this->extractTenantId($reference);
        if (!$tenantId) {
            return ['transactionStatus' => -1, 'errorMessage' => 'Invalid payment reference'];
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return ['transactionStatus' => -1, 'errorMessage' => 'Tenant not found'];
        }

        $tenant->configure();
        $transaction = Transaction::where('external_ref', $reference)
            ->orWhere('transaction_ref', $reference)
            ->first();

        if (!$transaction) {
            return ['transactionStatus' => -1, 'errorMessage' => 'Transaction not found'];
        }

        if ($transaction->status === 'success' && !$transaction->voucher_code) {
            app(VoucherService::class)->assignVoucherToTransaction($transaction->external_ref);
            $transaction->refresh();
        }

        return [
            'transactionStatus' => ['pending' => 0, 'success' => 1, 'failed' => -1][$transaction->status] ?? 0,
            'statusMessage' => $transaction->status_message,
            'voucherCode' => $transaction->voucher_code,
            'password' => $transaction->voucher_code,
        ];
    }

    public function handleIpn(array $postData): bool
    {
        $response = $this->yoAPI->receive_payment_notification();

        if (!($response['is_verified'] ?? false)) {
            Log::warning('Captive payment IPN verification failed', ['post_data' => $postData]);
            return false;
        }

        $externalRef = $response['external_ref'] ?? '';
        $tenantId = $this->extractTenantId($externalRef);
        $tenant = $tenantId ? Tenant::find($tenantId) : null;

        if (!$tenant) {
            return false;
        }

        $tenant->configure();
        $transaction = Transaction::where('external_ref', $externalRef)->first();

        if (!$transaction) {
            return false;
        }

        $transaction->update([
            'status' => 'success',
            'status_message' => $response['narrative'] ?? 'Payment confirmed',
            'network_ref' => $response['network_ref'] ?? null,
        ]);

        PlatformFee::recordFee($tenant->id, $externalRef, (float) ($response['amount'] ?: $transaction->amount), $response['network_ref'] ?? null);
        $voucher = app(VoucherService::class)->assignVoucherToTransaction($externalRef);

        if (($voucher['success'] ?? false) && $transaction->msisdn && $tenant->sms_enabled) {
            app()->instance('tenant', $tenant);
            app(SmsService::class)->sendVoucherSMS($transaction->msisdn, $voucher['voucherCode'], $voucher['password'] ?? '');
        }

        return true;
    }

    public function handleFailure(array $postData): bool
    {
        $response = $this->yoAPI->receive_payment_failure_notification();
        if (!($response['is_verified'] ?? false)) {
            return false;
        }

        $ref = $response['failed_transaction_reference'] ?? '';
        foreach (Tenant::where('status', 'approved')->get() as $tenant) {
            $tenant->configure();
            $updated = Transaction::where('transaction_ref', $ref)
                ->where('status', 'pending')
                ->update(['status' => 'failed', 'status_message' => 'Mobile money payment failed or was cancelled']);
            if ($updated) {
                return true;
            }
        }

        return false;
    }

    private function extractTenantId(string $ref): ?int
    {
        $parts = explode('_', $ref);
        return count($parts) >= 2 && $parts[0] === 'CAP' && is_numeric($parts[1]) ? (int) $parts[1] : null;
    }

    private function normalizeMsisdn(string $msisdn): string
    {
        $digits = preg_replace('/\D+/', '', $msisdn) ?? '';
        if (str_starts_with($digits, '0')) {
            return '256' . substr($digits, 1);
        }
        if (str_starts_with($digits, '7')) {
            return '256' . $digits;
        }
        return $digits;
    }
}
