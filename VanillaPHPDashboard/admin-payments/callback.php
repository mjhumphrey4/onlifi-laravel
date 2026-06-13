<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

cors();

try {
    $payload = requestData();
    logPayment('Generic callback received', $payload);

    $externalRef = (string) ($payload['externalId'] ?? $payload['external_ref'] ?? $payload['externalReference'] ?? '');
    if ($externalRef === '') {
        jsonResponse(['status' => 'error', 'message' => 'Missing external reference'], 422);
    }

    $status = strtolower((string) ($payload['status'] ?? $payload['TransactionStatus'] ?? ''));
    if (in_array($status, ['success', 'succeeded'], true)) {
        handleSuccessfulCollection($externalRef, [
            'status_message' => $payload['statusMessage'] ?? $payload['StatusMessage'] ?? 'Payment successful',
            'network_ref' => $payload['vendorTransactionId'] ?? $payload['network_ref'] ?? null,
            'raw_payload' => $payload,
        ]);
        jsonResponse(['status' => 'ok', 'message' => 'Callback processed']);
    }

    if (in_array($status, ['failed', 'declined', 'cancelled', 'canceled'], true)) {
        $tx = centralTransactionByRef($externalRef);
        if ($tx) {
            updateCentralTransaction($tx['external_ref'], [
                'status' => 'failed',
                'status_message' => $payload['statusMessage'] ?? $payload['StatusMessage'] ?? $status,
                'raw_payload' => $payload,
            ]);
            $site = siteBySlug($tx['slug']);
            if ($site) {
                $fresh = centralTransactionByRef($tx['external_ref']);
                mirrorToTenant($site, $fresh);
            }
        }
        jsonResponse(['status' => 'ok', 'message' => 'Failure recorded']);
    }

    jsonResponse(['status' => 'ok', 'message' => 'Callback acknowledged']);
} catch (Throwable $e) {
    logPayment('Generic callback error', ['error' => $e->getMessage()]);
    jsonResponse(['status' => 'error', 'message' => 'Callback processing failed'], 500);
}
