<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\SmsLog;
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
        $tenant = app()->bound('tenant') ? app('tenant') : null;
        $log = $this->createLog($tenant instanceof Tenant ? $tenant : null, $msisdn, $message);

        try {
            if ($tenant instanceof Tenant) {
                if (!$tenant->sms_enabled) {
                    $this->markLog($log, 'disabled', 'SMS plan is disabled for this tenant');
                    return [
                        'success' => false,
                        'message' => 'SMS plan is disabled for this tenant',
                    ];
                }
            }

            if ($this->provider === 'comms') {
                $result = $this->sendViaComms($msisdn, $message);
                $this->markLog(
                    $log,
                    ($result['success'] ?? false) ? 'sent' : 'failed',
                    ($result['success'] ?? false) ? null : ($result['message'] ?? 'SMS failed')
                );
                return $result;
            }

            Log::warning("SMS provider not configured, message not sent", [
                'msisdn' => $msisdn,
                'message' => $message,
            ]);

            $result = [
                'success' => false,
                'message' => 'SMS provider not configured',
            ];
            $this->markLog($log, 'failed', $result['message']);

            return $result;
        } catch (\Exception $e) {
            Log::error("SMS sending failed", [
                'msisdn' => $msisdn,
                'error' => $e->getMessage(),
            ]);
            $this->markLog($log, 'failed', $e->getMessage());

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

    private function createLog(?Tenant $tenant, string $msisdn, string $message): ?SmsLog
    {
        try {
            return SmsLog::create([
                'tenant_id' => $tenant?->id,
                'msisdn' => $msisdn,
                'message' => $message,
                'provider' => $this->provider,
                'status' => 'pending',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to create SMS log', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function markLog(?SmsLog $log, string $status, ?string $error = null): void
    {
        if (!$log) {
            return;
        }

        try {
            $log->update([
                'status' => $status,
                'error' => $error,
                'sent_at' => $status === 'sent' ? now() : $log->sent_at,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to update SMS log', ['error' => $e->getMessage()]);
        }
    }
}
