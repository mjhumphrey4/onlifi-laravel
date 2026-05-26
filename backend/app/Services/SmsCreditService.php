<?php

namespace App\Services;

use App\Models\SmsCreditTransaction;
use App\Models\SmsWallet;
use App\Models\SystemSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SmsCreditService
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

    public function wallet(Tenant $tenant): SmsWallet
    {
        return SmsWallet::firstOrCreate(['tenant_id' => $tenant->id], ['credits' => 0]);
    }

    public function initiateTopUp(Tenant $tenant, string $msisdn, float $amount): array
    {
        $creditPrice = max(1, (float) SystemSetting::get('sms_credit_price', 35));
        $credits = (int) floor($amount / $creditPrice);

        if ($credits < 1) {
            return ['status' => -1, 'errorMessage' => 'Amount is too low to buy SMS credits'];
        }

        $externalRef = sprintf('SMS_%d_%d_%s', $tenant->id, time(), uniqid());
        $payment = SmsCreditTransaction::create([
            'tenant_id' => $tenant->id,
            'external_ref' => $externalRef,
            'msisdn' => $this->normalizeMsisdn($msisdn),
            'amount' => $amount,
            'credits' => $credits,
            'status' => 'pending',
        ]);

        $this->yoAPI->set_external_reference($externalRef);
        $this->yoAPI->set_nonblocking('TRUE');
        $this->yoAPI->set_instant_notification_url(config('app.url') . '/api/sms-credits/ipn');
        $this->yoAPI->set_failure_notification_url(config('app.url') . '/api/sms-credits/failure');

        $response = $this->yoAPI->ac_deposit_funds(
            $payment->msisdn,
            $amount,
            'OnLiFi SMS Credits - ' . $tenant->name
        );

        if (($response['Status'] ?? null) === 'OK') {
            $payment->update([
                'transaction_ref' => $response['TransactionReference'] ?? null,
                'status_message' => $response['StatusMessage'] ?? 'Payment initiated',
            ]);

            return [
                'status' => 1,
                'message' => 'Confirm the mobile money prompt to top up SMS credits.',
                'externalReference' => $externalRef,
                'transactionReference' => $response['TransactionReference'] ?? null,
                'credits' => $credits,
            ];
        }

        $message = $response['StatusMessage'] ?? 'SMS credit top-up failed';
        $payment->update(['status' => 'failed', 'status_message' => $message]);

        return ['status' => -1, 'errorMessage' => $message, 'externalReference' => $externalRef];
    }

    public function status(string $reference, int $tenantId): array
    {
        $payment = SmsCreditTransaction::where('tenant_id', $tenantId)
            ->where(function ($query) use ($reference) {
                $query->where('external_ref', $reference)->orWhere('transaction_ref', $reference);
            })
            ->first();

        if (!$payment) {
            return ['transactionStatus' => -1, 'errorMessage' => 'SMS credit payment not found'];
        }

        return [
            'transactionStatus' => ['pending' => 0, 'success' => 1, 'failed' => -1][$payment->status] ?? 0,
            'status' => $payment->status,
            'statusMessage' => $payment->status_message,
            'credits' => $payment->credits,
            'wallet' => $payment->tenant ? $this->wallet($payment->tenant)->credits : 0,
        ];
    }

    public function handleIpn(array $postData): bool
    {
        $response = $this->yoAPI->receive_payment_notification();
        if (!($response['is_verified'] ?? false)) {
            Log::warning('SMS credit IPN verification failed', ['post_data' => $postData]);
            return false;
        }

        $externalRef = $response['external_ref'] ?? '';

        return DB::connection('central')->transaction(function () use ($response, $externalRef) {
            $payment = SmsCreditTransaction::where('external_ref', $externalRef)->lockForUpdate()->first();
            if (!$payment) {
                return false;
            }

            if ($payment->status === 'success') {
                return true;
            }

            $wallet = SmsWallet::where('tenant_id', $payment->tenant_id)->lockForUpdate()->first()
                ?: SmsWallet::create(['tenant_id' => $payment->tenant_id, 'credits' => 0]);

            $wallet->increment('credits', $payment->credits);
            $payment->update([
                'status' => 'success',
                'status_message' => $response['narrative'] ?? 'Payment confirmed',
                'network_ref' => $response['network_ref'] ?? null,
                'paid_at' => now(),
            ]);

            return true;
        });
    }

    public function handleFailure(array $postData): bool
    {
        $response = $this->yoAPI->receive_payment_failure_notification();
        if (!($response['is_verified'] ?? false)) {
            return false;
        }

        return (bool) SmsCreditTransaction::where('transaction_ref', $response['failed_transaction_reference'] ?? '')
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'status_message' => 'Mobile money payment failed or was cancelled']);
    }

    public function consume(Tenant $tenant, int $credits = 1): bool
    {
        return DB::connection('central')->transaction(function () use ($tenant, $credits) {
            $wallet = SmsWallet::where('tenant_id', $tenant->id)->lockForUpdate()->first()
                ?: SmsWallet::create(['tenant_id' => $tenant->id, 'credits' => 0]);

            if ($wallet->credits < $credits) {
                return false;
            }

            $wallet->decrement('credits', $credits);
            return true;
        });
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
