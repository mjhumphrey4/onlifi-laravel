<?php

namespace App\Services;

use App\Models\SubscriptionPayment;
use App\Models\SystemSetting;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionBillingService
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

    public function initiate(Tenant $tenant, string $msisdn, int $months = 1): array
    {
        $months = max(1, min($months, 12));
        $monthlyAmount = (float) SystemSetting::get('tenant_monthly_subscription_amount', 50000);
        $amount = round($monthlyAmount * $months, 2);
        $externalRef = sprintf('SUB_%d_%d_%s', $tenant->id, time(), uniqid());
        $narrative = sprintf('OnLiFi Subscription - %s - %d month%s', $tenant->name, $months, $months === 1 ? '' : 's');

        $payment = SubscriptionPayment::create([
            'tenant_id' => $tenant->id,
            'external_ref' => $externalRef,
            'msisdn' => $msisdn,
            'amount' => $amount,
            'months' => $months,
            'status' => 'pending',
            'narrative' => $narrative,
            'subscription_ends_at_before' => $tenant->subscription_ends_at,
        ]);

        $this->yoAPI->set_external_reference($externalRef);
        $this->yoAPI->set_nonblocking('TRUE');
        $this->yoAPI->set_instant_notification_url(config('app.url') . '/api/billing/ipn');
        $this->yoAPI->set_failure_notification_url(config('app.url') . '/api/billing/failure');

        $response = $this->yoAPI->ac_deposit_funds($msisdn, $amount, $narrative);

        if (($response['Status'] ?? null) === 'OK') {
            $payment->update([
                'transaction_ref' => $response['TransactionReference'] ?? null,
                'status_message' => $response['StatusMessage'] ?? 'Payment initiated',
            ]);

            return [
                'status' => 1,
                'message' => 'Payment initiated. Confirm the prompt on your phone.',
                'externalReference' => $externalRef,
                'transactionReference' => $response['TransactionReference'] ?? null,
                'amount' => $amount,
                'currency' => (string) SystemSetting::get('tenant_subscription_currency', 'UGX'),
                'months' => $months,
            ];
        }

        $message = $response['StatusMessage'] ?? 'Mobile money initiation failed';
        $payment->update([
            'status' => 'failed',
            'status_message' => $message,
        ]);

        Log::warning('Subscription payment initiation failed', [
            'tenant_id' => $tenant->id,
            'external_ref' => $externalRef,
            'message' => $message,
        ]);

        return [
            'status' => -1,
            'errorMessage' => $message,
            'externalReference' => $externalRef,
        ];
    }

    public function status(string $reference, ?int $tenantId = null): array
    {
        $payment = SubscriptionPayment::with('tenant')
            ->where(function ($query) use ($reference) {
                $query->where('external_ref', $reference)
                    ->orWhere('transaction_ref', $reference);
            })
            ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();

        if (!$payment) {
            return [
                'transactionStatus' => -1,
                'errorMessage' => 'Subscription payment not found',
            ];
        }

        $statusMap = [
            'success' => 1,
            'pending' => 0,
            'failed' => -1,
        ];

        return [
            'transactionStatus' => $statusMap[$payment->status] ?? 0,
            'status' => $payment->status,
            'statusMessage' => $payment->status_message,
            'amount' => (float) $payment->amount,
            'months' => $payment->months,
            'externalReference' => $payment->external_ref,
            'transactionReference' => $payment->transaction_ref,
            'subscriptionEndsAt' => $payment->subscription_ends_at_after?->toIso8601String()
                ?: $payment->tenant?->subscription_ends_at?->toIso8601String(),
            'billing' => $payment->tenant?->billingStatus(),
        ];
    }

    public function handleIpn(array $postData): bool
    {
        $response = $this->yoAPI->receive_payment_notification();

        if (!($response['is_verified'] ?? false)) {
            Log::warning('Subscription IPN verification failed', ['post_data' => $postData]);
            return false;
        }

        $externalRef = $response['external_ref'] ?? null;
        if (!$externalRef) {
            Log::warning('Subscription IPN missing external reference', ['response' => $response]);
            return false;
        }

        return DB::connection('central')->transaction(function () use ($response, $externalRef) {
            $payment = SubscriptionPayment::where('external_ref', $externalRef)->lockForUpdate()->first();

            if (!$payment) {
                Log::warning('Subscription IPN payment not found', ['external_ref' => $externalRef]);
                return false;
            }

            if ($payment->status === 'success') {
                return true;
            }

            $tenant = Tenant::whereKey($payment->tenant_id)->lockForUpdate()->first();
            if (!$tenant) {
                Log::warning('Subscription IPN tenant not found', ['payment_id' => $payment->id]);
                return false;
            }

            $startsAt = $tenant->subscription_ends_at && $tenant->subscription_ends_at->greaterThan(now())
                ? $tenant->subscription_ends_at->copy()
                : now();
            $endsAt = $startsAt->copy()->addMonthsNoOverflow($payment->months);

            $tenant->update([
                'subscription_ends_at' => $endsAt,
                'is_active' => true,
            ]);

            $payment->update([
                'status' => 'success',
                'status_message' => $response['narrative'] ?? 'Payment confirmed',
                'network_ref' => $response['network_ref'] ?? null,
                'msisdn' => $response['msisdn'] ?: $payment->msisdn,
                'amount' => $response['amount'] !== '' ? (float) $response['amount'] : $payment->amount,
                'subscription_ends_at_after' => $endsAt,
                'paid_at' => now(),
            ]);

            Log::info('Subscription payment confirmed', [
                'tenant_id' => $tenant->id,
                'external_ref' => $externalRef,
                'expires_at' => $endsAt->toDateTimeString(),
            ]);

            return true;
        });
    }

    public function handleFailure(array $postData): bool
    {
        $response = $this->yoAPI->receive_payment_failure_notification();

        if (!($response['is_verified'] ?? false)) {
            Log::warning('Subscription failure notification verification failed', ['post_data' => $postData]);
            return false;
        }

        $transactionRef = $response['failed_transaction_reference'] ?? null;
        if (!$transactionRef) {
            return false;
        }

        return (bool) SubscriptionPayment::where('transaction_ref', $transactionRef)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'status_message' => 'Mobile money payment failed or was cancelled',
            ]);
    }
}
