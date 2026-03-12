<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

class YoPaymentService
{
    private $yoAPI;
    private $username;
    private $password;
    private $mode;

    public function __construct()
    {
        $this->username = config('services.yoapi.username');
        $this->password = config('services.yoapi.password');
        $this->mode = config('services.yoapi.mode');
        
        require_once base_path('app/Services/YoAPI.php');
        $this->yoAPI = new \YoAPI($this->username, $this->password, $this->mode);
    }

    public function initiatePayment(array $data): array
    {
        $externalRef = 'TXN_' . time() . '_' . uniqid();
        
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

        $response = $this->yoAPI->ac_deposit_funds(
            $data['msisdn'],
            $data['amount'],
            'Feature Payment'
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
                'origin_site' => $data['origin_site'] ?? null,
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

        $statusMap = [
            'success' => 1,
            'pending' => 0,
            'failed' => -1,
        ];

        return [
            'transactionStatus' => $statusMap[$transaction->status] ?? 0,
            'statusMessage' => $transaction->status_message,
            'voucherCode' => $transaction->voucher_code,
        ];
    }

    public function handleIPN(array $postData): bool
    {
        $response = $this->yoAPI->receive_payment_notification();

        if ($response['is_verified']) {
            $externalRef = $response['external_ref'];
            $msisdn = $response['msisdn'];
            $amount = $response['amount'];
            $networkRef = $response['network_ref'];
            $narrative = $response['narrative'];

            $transaction = Transaction::where('external_ref', $externalRef)->first();

            if ($transaction) {
                $transaction->update([
                    'status' => 'success',
                    'status_message' => $narrative,
                    'network_ref' => $networkRef,
                ]);

                Log::info("IPN verified and processed", [
                    'external_ref' => $externalRef,
                    'msisdn' => $msisdn,
                    'amount' => $amount,
                ]);

                return true;
            }
        }

        Log::warning("IPN verification failed", ['post_data' => $postData]);
        return false;
    }
}
