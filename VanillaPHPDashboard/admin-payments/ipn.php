<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/YoAPI.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

http_response_code(200);

try {
    $yo = appConfig('yo');
    $yoApi = new YoAPI($yo['username'], $yo['password'], $yo['mode']);
    $response = $yoApi->receive_payment_notification();
    logPayment('Yo IPN received', $response);

    if (empty($response['is_verified'])) {
        logPayment('Yo IPN verification failed', ['post' => $_POST]);
        exit('OK');
    }

    $externalRef = (string) ($response['external_ref'] ?? '');
    if ($externalRef === '') {
        exit('OK');
    }

    handleSuccessfulCollection($externalRef, [
        'status_message' => $response['narrative'] ?? 'Payment successful',
        'network_ref' => $response['network_ref'] ?? null,
        'raw_payload' => $response,
    ]);
} catch (Throwable $e) {
    logPayment('Yo IPN error', ['error' => $e->getMessage(), 'post' => $_POST]);
}

echo 'OK';
