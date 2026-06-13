<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/YoAPI.php';

cors();

try {
    $data = requestData();
    $site = requireSite($data);

    foreach (['amount', 'msisdn', 'client_mac', 'voucher_type', 'origin_url'] as $field) {
        if (!isset($data[$field]) || trim((string) $data[$field]) === '') {
            jsonResponse(['status' => -1, 'errorMessage' => "Missing required field: $field"], 422);
        }
    }

    $amount = (float) $data['amount'];
    if ($amount <= 0) {
        jsonResponse(['status' => -1, 'errorMessage' => 'Amount must be greater than zero.'], 422);
    }

    $msisdn = normalizePhone((string) $data['msisdn']);
    if (!preg_match('/^256\d{9}$/', $msisdn)) {
        jsonResponse(['status' => -1, 'errorMessage' => 'Invalid phone number. Use 256XXXXXXXXX.'], 422);
    }

    $externalRef = 'YO_' . date('YmdHis') . '_' . bin2hex(random_bytes(5));
    $txId = insertCentralTransaction($site, [
        'transaction_type' => 'collection',
        'provider' => 'yo',
        'external_ref' => $externalRef,
        'msisdn' => $msisdn,
        'amount' => $amount,
        'status' => 'pending',
        'origin_site' => $site['origin_site'],
        'client_mac' => normalizeMac($data['client_mac']),
        'email' => $data['email'] ?? null,
        'voucher_type' => $data['voucher_type'],
        'origin_url' => $data['origin_url'],
        'raw_payload' => $data,
    ]);

    $tx = centralTransactionByRef($externalRef);
    mirrorToTenant($site, $tx);

    $yo = appConfig('yo');
    $yoApi = new YoAPI($yo['username'], $yo['password'], $yo['mode']);
    $yoApi->set_external_reference($externalRef);
    $yoApi->set_nonblocking('TRUE');
    $yoApi->set_instant_notification_url(publicEndpointUrl($site, 'ipn.php'));
    $yoApi->set_failure_notification_url(publicEndpointUrl($site, 'failure.php'));

    $response = $yoApi->ac_deposit_funds($msisdn, $amount, 'Onlifi voucher payment');
    logPayment('Yo initiate response', ['site' => $site['slug'], 'external_ref' => $externalRef, 'response' => $response]);

    if (($response['Status'] ?? '') === 'OK') {
        $providerRef = $response['TransactionReference'] ?? null;
        updateCentralTransaction($externalRef, [
            'transaction_ref' => $providerRef,
            'status_message' => $response['StatusMessage'] ?? 'Payment request sent',
        ]);

        $tx = centralTransactionByRef($externalRef);
        mirrorToTenant($site, $tx);

        jsonResponse([
            'status' => 1,
            'transactionReference' => $providerRef,
            'externalReference' => $externalRef,
            'statusMessage' => $response['StatusMessage'] ?? 'Check your phone to confirm the payment.',
            'site' => $site['slug'],
        ]);
    }

    updateCentralTransaction($externalRef, [
        'status' => 'failed',
        'status_message' => $response['StatusMessage'] ?? $response['ErrorMessage'] ?? 'Payment initiation failed',
        'raw_payload' => $response,
    ]);
    $tx = centralTransactionByRef($externalRef);
    mirrorToTenant($site, $tx);

    jsonResponse([
        'status' => -1,
        'externalReference' => $externalRef,
        'errorMessage' => $response['StatusMessage'] ?? $response['ErrorMessage'] ?? 'Payment initiation failed',
    ], 502);
} catch (Throwable $e) {
    logPayment('Initiate error', ['error' => $e->getMessage()]);
    jsonResponse(['status' => -1, 'errorMessage' => 'Payment initiation failed.'], 500);
}
