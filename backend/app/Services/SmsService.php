<?php

namespace App\Services;

use App\Models\Tenant;
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
            $tenant = app()->bound('tenant') ? app('tenant') : null;
            if ($tenant instanceof Tenant) {
                if (!$tenant->sms_enabled) {
                    return [
                        'success' => false,
                        'message' => 'SMS plan is disabled for this tenant',
                    ];
                }

                $wallet = app(SmsCreditService::class)->wallet($tenant);
                if ($wallet->credits < 1) {
                    return [
                        'success' => false,
                        'message' => 'Insufficient SMS credits',
                    ];
                }
            }

            if ($this->provider === 'comms') {
                $result = $this->sendViaComms($msisdn, $message);
                if (($result['success'] ?? false) && $tenant instanceof Tenant) {
                    app(SmsCreditService::class)->consume($tenant);
                }
                return $result;
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
