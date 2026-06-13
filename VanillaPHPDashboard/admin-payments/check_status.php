<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/YoAPI.php';

cors();

$ref = trim((string) ($_GET['ref'] ?? ''));
if ($ref === '') {
    jsonResponse(['transactionStatus' => -1, 'errorMessage' => 'Missing transaction reference.'], 422);
}

try {
    $tx = centralTransactionByRef($ref);
    if (!$tx) {
        jsonResponse(['transactionStatus' => -1, 'errorMessage' => 'Transaction not found.'], 404);
    }

    if ($tx['status'] === 'success') {
        jsonResponse([
            'transactionStatus' => 1,
            'statusMessage' => 'Payment successful',
            'voucherCode' => $tx['voucher_code'],
        ]);
    }

    if ($tx['status'] === 'failed') {
        jsonResponse([
            'transactionStatus' => -1,
            'errorMessage' => $tx['status_message'] ?: 'Payment failed',
        ]);
    }

    $providerRef = $tx['transaction_ref'] ?: $tx['external_ref'];
    $yo = appConfig('yo');
    $yoApi = new YoAPI($yo['username'], $yo['password'], $yo['mode']);
    $response = $yoApi->ac_transaction_check_status($providerRef);
    logPayment('Yo status response', ['ref' => $ref, 'response' => $response]);

    $providerStatus = strtoupper((string) ($response['TransactionStatus'] ?? ''));
    if ($providerStatus === 'SUCCEEDED') {
        $fresh = handleSuccessfulCollection($tx['external_ref'], [
            'status_message' => $response['StatusMessage'] ?? 'Payment successful',
            'network_ref' => $response['NetworkReference'] ?? null,
            'raw_payload' => $response,
        ]);

        jsonResponse([
            'transactionStatus' => 1,
            'statusMessage' => 'Payment successful',
            'voucherCode' => $fresh['voucher_code'],
        ]);
    }

    if (in_array($providerStatus, ['FAILED', 'DECLINED', 'CANCELLED'], true)) {
        updateCentralTransaction($tx['external_ref'], [
            'status' => 'failed',
            'status_message' => $response['StatusMessage'] ?? $response['ErrorMessage'] ?? 'Payment failed',
            'raw_payload' => $response,
        ]);

        $site = siteBySlug($tx['slug']);
        if ($site) {
            $fresh = centralTransactionByRef($tx['external_ref']);
            mirrorToTenant($site, $fresh);
        }

        jsonResponse([
            'transactionStatus' => -1,
            'errorMessage' => $response['StatusMessage'] ?? $response['ErrorMessage'] ?? 'Payment failed',
        ]);
    }

    jsonResponse([
        'transactionStatus' => 0,
        'statusMessage' => $response['StatusMessage'] ?? 'Waiting for confirmation',
    ]);
} catch (Throwable $e) {
    logPayment('Status check error', ['ref' => $ref, 'error' => $e->getMessage()]);
    jsonResponse(['transactionStatus' => -1, 'errorMessage' => 'Could not check payment status.'], 500);
}
