<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\PlatformFee;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;

class YoPaymentService
{
    private $yoAPI;
    private $username;
    private $password;
    private $mode;
    private $tenant;
    private $tenantId;

    public function __construct()
    {
        // Get current tenant if available
        $this->tenant = app()->bound('tenant') ? app('tenant') : null;
        $this->tenantId = $this->tenant ? $this->tenant->id : null;
        
        // ALWAYS use platform YoAPI credentials - all payments go through OnLiFi
        // Platform fees are deducted and tracked separately
        $this->username = config('services.yoapi.username');
        $this->password = config('services.yoapi.password');
        $this->mode = config('services.yoapi.mode', 'sandbox');
        
        require_once base_path('app/Services/YoAPI.php');
        $this->yoAPI = new \YoAPI($this->username, $this->password, $this->mode);
    }

    /**
     * Get current platform fee percentage
     */
    public function getPlatformFeePercent(): float
    {
        return (float) SystemSetting::get('platform_collection_fee_percent', 5);
    }

    /**
     * Calculate platform fee for an amount
     */
    public function calculatePlatformFee(float $amount): array
    {
        $feePercent = $this->getPlatformFeePercent();
        $platformFee = round($amount * ($feePercent / 100), 2);
        $netAmount = $amount - $platformFee;

        return [
            'gross_amount' => $amount,
            'platform_fee' => $platformFee,
            'net_amount' => $netAmount,
            'fee_percent' => $feePercent,
        ];
    }

    public function initiatePayment(array $data): array
    {
        // Generate unique reference that includes tenant ID for tracking
        $externalRef = sprintf('TXN_%d_%d_%s', 
            $this->tenantId ?? 0, 
            time(), 
            uniqid()
        );
        
        $transaction = Transaction::create([
            'external_ref' => $externalRef,
            'msisdn' => $data['msisdn'],
            'amount' => $data['amount'],
            'status' => 'pending',
            'origin_site' => $data['origin_site'] ?? null,
            'client_mac' => $data['client_mac'] ?? null,
            'email' => $data['email'] ?? null,
            'voucher_type' => $data['voucher_type'] ?? null,
            'origin_url' => $data['origin_url'] ?? null,
        ]);

        $this->yoAPI->set_external_reference($externalRef);
        $this->yoAPI->set_nonblocking("TRUE");
        
        $ipnUrl = config('app.url') . '/api/payments/ipn';
        $failureUrl = config('app.url') . '/api/payments/failure';
        
        $this->yoAPI->set_instant_notification_url($ipnUrl);
        $this->yoAPI->set_failure_notification_url($failureUrl);

        // Build narrative with tenant info for tracking
        $narrative = sprintf('OnLiFi WiFi - %s', 
            $this->tenant ? $this->tenant->name : 'Platform'
        );

        $response = $this->yoAPI->ac_deposit_funds(
            $data['msisdn'],
            $data['amount'],
            $narrative
        );

        if ($response['Status'] == 'OK') {
            $yoTransactionRef = $response['TransactionReference'] ?? '';
            $statusMessage = $response['StatusMessage'] ?? '';

            $transaction->update([
                'transaction_ref' => $yoTransactionRef,
                'status_message' => $statusMessage,
            ]);

            Log::info("Payment initiated", [
                'external_ref' => $externalRef,
                'yo_ref' => $yoTransactionRef,
                'tenant_id' => $this->tenantId,
                'amount' => $data['amount'],
            ]);

            return [
                'status' => 1,
                'transactionReference' => $yoTransactionRef,
                'externalReference' => $externalRef,
                'statusMessage' => $statusMessage,
            ];
        } else {
            $errorMessage = $response['StatusMessage'] ?? 'Unknown error from Yo! Payments';
            
            $transaction->update([
                'status' => 'failed',
                'status_message' => $errorMessage,
            ]);

            Log::error("Payment initiation failed", [
                'external_ref' => $externalRef,
                'tenant_id' => $this->tenantId,
                'error' => $errorMessage,
            ]);

            return [
                'status' => -1,
                'errorMessage' => $errorMessage,
                'externalReference' => $externalRef,
            ];
        }
    }

    public function checkTransactionStatus(string $transactionRef): array
    {
        $transaction = Transaction::where('transaction_ref', $transactionRef)
            ->orWhere('external_ref', $transactionRef)
            ->first();

        if (!$transaction) {
            return [
                'transactionStatus' => -1,
                'errorMessage' => 'Transaction not found',
            ];
        }

        // Calculate what tenant will receive after fees
        $feeInfo = $this->calculatePlatformFee((float) $transaction->amount);

        $statusMap = [
            'success' => 1,
            'pending' => 0,
            'failed' => -1,
        ];

        return [
            'transactionStatus' => $statusMap[$transaction->status] ?? 0,
            'statusMessage' => $transaction->status_message,
            'voucherCode' => $transaction->voucher_code,
            'grossAmount' => $feeInfo['gross_amount'],
            'netAmount' => $feeInfo['net_amount'],  // What tenant receives
            'platformFee' => $feeInfo['platform_fee'],  // Hidden from customer
        ];
    }

    public function handleIPN(array $postData): bool
    {
        $response = $this->yoAPI->receive_payment_notification();

        if ($response['is_verified']) {
            $externalRef = $response['external_ref'];
            $msisdn = $response['msisdn'];
            $amount = (float) $response['amount'];
            $networkRef = $response['network_ref'];
            $narrative = $response['narrative'];

            $transaction = Transaction::where('external_ref', $externalRef)->first();

            if ($transaction) {
                $transaction->update([
                    'status' => 'success',
                    'status_message' => $narrative,
                    'network_ref' => $networkRef,
                ]);

                // Extract tenant ID from external reference (format: TXN_{tenant_id}_{timestamp}_{uniqid})
                $tenantId = $this->extractTenantIdFromRef($externalRef);

                // Record platform fee if we have a tenant
                if ($tenantId) {
                    PlatformFee::recordFee(
                        $tenantId,
                        $externalRef,
                        $amount,
                        $response['network_ref'] ?? null
                    );
                }

                Log::info("IPN verified and processed", [
                    'external_ref' => $externalRef,
                    'tenant_id' => $tenantId,
                    'msisdn' => $msisdn,
                    'amount' => $amount,
                ]);

                return true;
            }
        }

        Log::warning("IPN verification failed", ['post_data' => $postData]);
        return false;
    }

    /**
     * Extract tenant ID from transaction reference
     * Format: TXN_{tenant_id}_{timestamp}_{uniqid}
     */
    private function extractTenantIdFromRef(string $ref): ?int
    {
        $parts = explode('_', $ref);
        if (count($parts) >= 2 && is_numeric($parts[1])) {
            $tenantId = (int) $parts[1];
            return $tenantId > 0 ? $tenantId : null;
        }
        return null;
    }

    /**
     * Get tenant's transaction summary with fees
     */
    public function getTenantTransactionSummary(int $tenantId): array
    {
        $fees = PlatformFee::where('tenant_id', $tenantId)
            ->where('status', 'collected')
            ->selectRaw('
                SUM(gross_amount) as total_gross,
                SUM(platform_fee) as total_fees,
                SUM(net_amount) as total_net,
                COUNT(*) as transaction_count
            ')
            ->first();

        return [
            'total_collected' => (float) ($fees->total_gross ?? 0),
            'platform_fees' => (float) ($fees->total_fees ?? 0),
            'net_earnings' => (float) ($fees->total_net ?? 0),
            'transaction_count' => (int) ($fees->transaction_count ?? 0),
        ];
    }
}
