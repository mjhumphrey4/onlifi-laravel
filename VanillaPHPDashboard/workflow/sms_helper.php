<?php
// sms_helper.php
// SMS notification helper for sending voucher codes to customers

function logSmsEvent($message, $type = 'INFO') {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/sms_log_' . date('Y-m-d') . '.txt';
    $entry = '[' . date('Y-m-d H:i:s') . "] [$type] $message\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

function smsConfig($name, $fallback = '') {
    return defined($name) ? constant($name) : $fallback;
}

/**
 * Send voucher code via SMS to customer
 * 
 * @param string $msisdn Phone number in format 256XXXXXXXXX
 * @param string $voucherCode The voucher code to send
 * @param string $packageName Optional package name for context
 * @return array ['success' => bool, 'message' => string, 'response' => mixed]
 */
function sendVoucherSMS($msisdn, $voucherCode, $packageName = '') {
    logSmsEvent("Preparing voucher SMS to $msisdn for voucher $voucherCode", 'SMS_START');

    try {
        $packageInfo = $packageName ? " for $packageName" : "";
        $brand = smsConfig('SMS_BRAND_NAME', 'ONLIFI WiFi');
        $message = "$brand: Your$packageInfo voucher code is $voucherCode. Thank you.";
        $result = sendSmsWithRetries($msisdn, $message);
        
        if (!empty($result['success'])) {
            logSmsEvent("Voucher SMS sent to $msisdn for voucher $voucherCode", 'SMS_SUCCESS');
            return [
                'success' => true,
                'message' => 'SMS sent successfully',
                'response' => $result['response'] ?? null
            ];
        }

        logSmsEvent("Voucher SMS failed to $msisdn for voucher $voucherCode: " . ($result['message'] ?? 'Unknown error'), 'SMS_ERROR');
        return [
            'success' => false,
            'message' => $result['message'] ?? 'SMS sending failed',
            'response' => $result['response'] ?? null
        ];
        
    } catch (Throwable $e) {
        $error = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        logSmsEvent("Voucher SMS throwable for $msisdn: $error", 'SMS_ERROR');
        return [
            'success' => false,
            'message' => 'SMS error: ' . $error,
            'response' => null
        ];
    }
}

/**
 * Send payment confirmation SMS (without voucher)
 * 
 * @param string $msisdn Phone number in format 256XXXXXXXXX
 * @param string $amount Payment amount
 * @param string $status Payment status message
 * @return array ['success' => bool, 'message' => string, 'response' => mixed]
 */
function sendPaymentStatusSMS($msisdn, $amount, $status) {
    try {
        $brand = smsConfig('SMS_BRAND_NAME', 'ONLIFI WiFi');
        $message = "$brand: Payment of UGX $amount - $status.";
        $result = sendSmsWithRetries($msisdn, $message);
        
        return [
            'success' => !empty($result['success']),
            'message' => !empty($result['success']) ? 'SMS sent successfully' : ($result['message'] ?? 'SMS sending failed'),
            'response' => $result['response'] ?? null
        ];
        
    } catch (Throwable $e) {
        $error = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        return [
            'success' => false,
            'message' => 'SMS error: ' . $error,
            'response' => null
        ];
    }
}

/**
 * Send TWO voucher codes via SMS to customer (for special 10,000/= 7-day package)
 * 
 * @param string $msisdn Phone number in format 256XXXXXXXXX
 * @param array $voucherCodes Array of two voucher codes to send
 * @param string $packageName Optional package name for context
 * @return array ['success' => bool, 'message' => string, 'response' => mixed]
 */
function sendTwoVouchersSMS($msisdn, $voucherCodes, $packageName = '') {
    if (!is_array($voucherCodes) || count($voucherCodes) < 2) {
        return [
            'success' => false,
            'message' => 'Two voucher codes required',
            'response' => null
        ];
    }
    
    try {
        logSmsEvent("Preparing two-voucher SMS to $msisdn for vouchers " . implode(', ', $voucherCodes), 'SMS_START');
        $packageInfo = $packageName ? " for $packageName" : "";
        $brand = smsConfig('SMS_BRAND_NAME', 'ONLIFI WiFi');
        $message = "$brand: Your$packageInfo voucher codes are " . $voucherCodes[0] . " and " . $voucherCodes[1] . ". Thank you.";
        
        $result = sendSmsWithRetries($msisdn, $message);
        
        if (!empty($result['success'])) {
            logSmsEvent("Two-voucher SMS sent to $msisdn", 'SMS_SUCCESS');
            return [
                'success' => true,
                'message' => 'SMS sent successfully with 2 voucher codes',
                'response' => $result['response'] ?? null
            ];
        }

        logSmsEvent("Two-voucher SMS failed to $msisdn: " . ($result['message'] ?? 'Unknown error'), 'SMS_ERROR');
        return [
            'success' => false,
            'message' => $result['message'] ?? 'SMS sending failed',
            'response' => $result['response'] ?? null
        ];
        
    } catch (Throwable $e) {
        $error = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        logSmsEvent("Two-voucher SMS throwable for $msisdn: $error", 'SMS_ERROR');
        return [
            'success' => false,
            'message' => 'SMS error: ' . $error,
            'response' => null
        ];
    }
}

/**
 * Check SMS account balance
 * 
 * @return array ['success' => bool, 'balance' => float|null, 'message' => string]
 */
function checkSMSBalance() {
    $result = smsProviderRequest([
        'method' => 'Balance',
        'userdata' => [
            'username' => smsConfig('SMS_USERNAME', 'humphreympairwe'),
            'password' => smsConfig('SMS_API_KEY', '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d'),
        ],
    ], 8);

    if (empty($result['success'])) {
        return [
            'success' => false,
            'balance' => null,
            'message' => $result['message'] ?? 'Could not check SMS balance',
        ];
    }

    $response = $result['response'] ?? [];
    $balance = $response['Balance'] ?? $response['balance'] ?? null;

    return [
        'success' => true,
        'balance' => $balance,
        'message' => "Balance: $balance",
    ];
}

function sendSmsWithRetries(string $msisdn, string $message) {
    $attempts = 3;
    $lastResult = ['success' => false, 'message' => 'SMS was not attempted', 'response' => null];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $lastResult = sendSmsDirect($msisdn, $message, $attempt);

        if (!empty($lastResult['success'])) {
            return $lastResult;
        }

        if ($attempt < $attempts) {
            usleep(250000);
        }
    }

    return $lastResult;
}

function sendSmsDirect(string $msisdn, string $message, int $attempt): array {
    $username = smsConfig('SMS_USERNAME', 'humphreympairwe');
    $apiKey = smsConfig('SMS_API_KEY', '32ccb38b175de8d61ce05263e9cadfd522f258bac05f931d');
    $senderId = smsConfig('SMS_SENDER_ID', 'ONLIFI');
    $apiUrl = smsConfig('SMS_API_URL', 'https://comms.egosms.co/api/v1/json/');

    $payload = [
        'method' => 'SendSms',
        'userdata' => [
            'username' => $username,
            'password' => $apiKey,
        ],
        'msgdata' => [[
            'number' => $msisdn,
            'message' => $message,
            'senderid' => $senderId,
            'priority' => '0',
        ]],
    ];

    logSmsEvent("Attempt $attempt sending SMS to $msisdn via $apiUrl", 'SMS_ATTEMPT');

    $result = smsProviderRequest($payload, 8, $apiUrl);
    if (empty($result['success'])) {
        return $result;
    }

    $data = $result['response'] ?? [];
    $status = $data['Status'] ?? $data['status'] ?? '';
    if (strtoupper((string) $status) === 'OK') {
        return ['success' => true, 'message' => 'SMS sent successfully', 'response' => $data];
    }

    $messageText = $data['Message'] ?? $data['message'] ?? $data['ErrorMessage'] ?? 'SMS provider rejected the request';
    return ['success' => false, 'message' => $messageText, 'response' => $data];
}

function smsProviderRequest(array $payload, int $timeoutSeconds = 8, ?string $apiUrl = null): array {
    $apiUrl = $apiUrl ?: smsConfig('SMS_API_URL', 'https://comms.egosms.co/api/v1/json/');
    $json = json_encode($payload);

    if ($json === false) {
        return ['success' => false, 'message' => 'Could not encode SMS request JSON', 'response' => null];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false || $curlError !== '') {
            return ['success' => false, 'message' => 'SMS HTTP error: ' . $curlError, 'response' => null];
        }

        return parseSmsProviderResponse((string) $body, $httpCode);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $json,
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($apiUrl, false, $context);
    $httpCode = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                $httpCode = (int) $matches[1];
                break;
            }
        }
    }

    if ($body === false) {
        return ['success' => false, 'message' => 'SMS HTTP request failed and cURL is unavailable', 'response' => null];
    }

    return parseSmsProviderResponse((string) $body, $httpCode);
}

function parseSmsProviderResponse(string $body, int $httpCode): array {
    $data = json_decode($body, true);

    if (!is_array($data)) {
        return [
            'success' => false,
            'message' => "SMS provider returned invalid JSON with HTTP $httpCode",
            'response' => substr($body, 0, 500),
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $messageText = $data['Message'] ?? $data['message'] ?? $data['ErrorMessage'] ?? 'SMS provider returned HTTP error';
        return ['success' => false, 'message' => "HTTP $httpCode: $messageText", 'response' => $data];
    }

    return ['success' => true, 'message' => 'SMS provider responded', 'response' => $data];
}
?>
