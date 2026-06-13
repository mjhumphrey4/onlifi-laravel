<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

http_response_code(200);

$payload = $_POST ?: requestData();
$externalRef = (string) ($payload['ExternalReference'] ?? $payload['external_ref'] ?? $payload['externalReference'] ?? '');

if ($externalRef !== '') {
    $tx = centralTransactionByRef($externalRef);
    if ($tx && $tx['status'] === 'pending') {
        updateCentralTransaction($tx['external_ref'], [
            'status' => 'failed',
            'status_message' => $payload['StatusMessage'] ?? $payload['ErrorMessage'] ?? 'Provider sent failure callback',
            'raw_payload' => $payload,
        ]);
        $site = siteBySlug($tx['slug']);
        if ($site) {
            $fresh = centralTransactionByRef($tx['external_ref']);
            mirrorToTenant($site, $fresh);
        }
    }
}

logPayment('Failure callback', ['payload' => $payload]);
echo 'OK';
