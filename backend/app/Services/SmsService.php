<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SmsService
{
    private $provider;
    private $apiKey;
    private $senderId;

    public function __construct()
    {
        $this->provider = config('services.sms.provider');
        $this->apiKey = config('services.sms.api_key');
        $this->senderId = config('services.sms.sender_id');
    }

    public function sendVoucherSMS(string $msisdn, string $voucherCode, string $password = ''): array
    {
        $message = "Your OnLiFi voucher code is: {$voucherCode}";
        
        if ($password) {
            $message .= " Password: {$password}";
        }
        
        $message .= ". Thank you for your payment!";

        return $this->sendSMS($msisdn, $message);
    }

    public function sendSMS(string $msisdn, string $message): array
    {
        try {
            if ($this->provider === 'comms') {
                return $this->sendViaComms($msisdn, $message);
            }

            Log::warning("SMS provider not configured, message not sent", [
                'msisdn' => $msisdn,
                'message' => $message,
            ]);

            return [
                'success' => false,
                'message' => 'SMS provider not configured',
            ];
        } catch (\Exception $e) {
            Log::error("SMS sending failed", [
                'msisdn' => $msisdn,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function sendViaComms(string $msisdn, string $message): array
    {
        if (!$this->apiKey) {
            return [
                'success' => false,
                'message' => 'SMS API key not configured',
            ];
        }

        Log::info("SMS would be sent via Comms SDK", [
            'msisdn' => $msisdn,
            'message' => $message,
        ]);

        return [
            'success' => true,
            'message' => 'SMS sent successfully (simulated)',
        ];
    }
}
