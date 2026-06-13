<?php

require_once __DIR__ . '/site_registry.php';

function onlifiExtractProviderNumber($data, array $keys) {
    if (!is_array($data)) return null;
    foreach ($data as $key => $value) {
        if (in_array(strtolower((string)$key), $keys, true) && is_numeric($value)) {
            return (float)$value;
        }
        if (is_array($value)) {
            $found = onlifiExtractProviderNumber($value, $keys);
            if ($found !== null) return $found;
        }
    }
    return null;
}

function onlifiMamboRequest($url, $apiKey, array $payload = null) {
    $ch = curl_init($url);
    $headers = [
        'Authorization: ' . $apiKey,
        'Accept: application/json',
    ];

    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $json = $raw ? json_decode($raw, true) : null;
    return [
        'http_code' => $httpCode,
        'error' => $error,
        'raw' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function onlifiLogSmsAttempt(array $site = null, $recipient, $message, array $config, $status, $providerMessage, array $providerResponse = null, $externalRef = null) {
    try {
        onlifiLogSms([
            'site_id' => $site['id'] ?? null,
            'site_slug' => $site['slug'] ?? null,
            'external_ref' => $externalRef,
            'recipient' => $recipient,
            'sender_id' => $config['sender_id'] ?? null,
            'message_category' => $config['message_category'] ?? null,
            'message' => $message,
            'status' => $status,
            'provider_message' => $providerMessage,
            'provider_response' => $providerResponse ? json_encode($providerResponse) : null,
            'provider_cost' => onlifiExtractProviderNumber($providerResponse['json'] ?? null, ['cost', 'sms_cost', 'amount']),
            'provider_balance' => onlifiExtractProviderNumber($providerResponse['json'] ?? null, ['balance', 'account_balance']),
        ]);
    } catch (Exception $e) {
        error_log('SMS log error: ' . $e->getMessage());
    }
}

function onlifiSendSms($msisdn, $message, $externalRef = null) {
    $site = onlifiCurrentSite();
    $config = onlifiSmsConfig($site);
    $externalRef = $externalRef ?: ($GLOBALS['CURRENT_SMS_EXTERNAL_REF'] ?? null);

    if (!$site) {
        return ['success' => false, 'message' => 'Unknown payment site', 'response' => null];
    }

    if (empty($site['sms_enabled'])) {
        return ['success' => true, 'message' => 'SMS disabled for this site', 'response' => ['skipped' => true]];
    }

    if (empty($config['api_key'])) {
        onlifiLogSmsAttempt($site, $msisdn, $message, $config, 'failed', 'Missing MamboSMS API key', null, $externalRef);
        return ['success' => false, 'message' => 'Missing MamboSMS API key', 'response' => null];
    }

    $payload = [
        'message' => $message,
        'recipients' => [$msisdn],
        'message_category' => $config['message_category'],
        'sender_id' => $config['sender_id'],
    ];

    $response = onlifiMamboRequest($config['send_url'], $config['api_key'], $payload);
    $ok = $response['error'] === '' && $response['http_code'] >= 200 && $response['http_code'] < 300;
    $status = $ok ? 'sent' : 'failed';
    $providerMessage = $response['error'] ?: (($response['json']['message'] ?? $response['json']['status'] ?? null) ?: 'MamboSMS response HTTP ' . $response['http_code']);

    onlifiLogSmsAttempt($site, $msisdn, $message, $config, $status, $providerMessage, $response, $externalRef);

    return [
        'success' => $ok,
        'message' => $ok ? 'SMS sent successfully' : 'SMS sending failed: ' . $providerMessage,
        'response' => $response,
    ];
}

function sendVoucherSMS($msisdn, $voucherCode, $packageName = '') {
    $site = onlifiCurrentSite();
    $config = onlifiSmsConfig($site);
    $brand = $config['brand_name'] ?: 'ONLIFI WiFi';
    $packageInfo = $packageName ? " for $packageName" : "";
    $message = "$brand: Your$packageInfo voucher code is $voucherCode. Thank you for your payment.";
    return onlifiSendSms($msisdn, $message);
}

function sendPaymentStatusSMS($msisdn, $amount, $status) {
    $site = onlifiCurrentSite();
    $config = onlifiSmsConfig($site);
    $brand = $config['brand_name'] ?: 'ONLIFI WiFi';
    $message = "$brand: Payment of UGX $amount - $status.";
    return onlifiSendSms($msisdn, $message);
}

function sendCustomSMS($msisdn, $message) {
    return onlifiSendSms($msisdn, $message);
}

function checkSMSBalance() {
    $site = onlifiCurrentSite();
    $config = onlifiSmsConfig($site);

    if (empty($config['api_key'])) {
        return ['success' => false, 'balance' => null, 'message' => 'Missing MamboSMS API key'];
    }

    $response = onlifiMamboRequest($config['balance_url'], $config['api_key']);
    $balance = onlifiExtractProviderNumber($response['json'] ?? null, ['balance', 'account_balance']);
    $ok = $response['error'] === '' && $response['http_code'] >= 200 && $response['http_code'] < 300;

    return [
        'success' => $ok,
        'balance' => $balance,
        'message' => $ok ? 'Balance loaded' : ($response['error'] ?: 'Balance request failed'),
        'response' => $response,
    ];
}

?>
