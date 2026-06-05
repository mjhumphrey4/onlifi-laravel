<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();

require_once 'config.php';
require_once 'auth_helper.php';
require_once 'logger.php';

ob_end_clean();

logIotec("=== PAYMENT INITIATION STARTED ===", 'INITIATE');

$input = file_get_contents('php://input');
logIotec("Received payment request", 'INITIATE', ['rawInput' => substr($input, 0, 500)]);

$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    $jsonError = json_last_error_msg();
    logIotec("JSON decode error: $jsonError", 'INITIATE_ERROR', ['input' => substr($input, 0, 500)]);
    echo json_encode(['error' => "Invalid JSON input: $jsonError"]);
    exit;
}

if (!isset($data['amount']) || !isset($data['msisdn']) || !isset($data['origin_site']) 
    || !isset($data['client_mac']) || !isset($data['voucher_type']) || !isset($data['origin_url'])) {
    logIotec("Missing required fields", 'INITIATE_ERROR', ['receivedData' => $data]);
    echo json_encode(['error' => 'Missing required fields: amount, msisdn, origin_site, client_mac, voucher_type, origin_url']);
    exit;
}

$amount = (float) $data['amount'];
$msisdn = $data['msisdn'];
$originSite = trim($data['origin_site']);
$clientMac = $data['client_mac'];
$email = $data['email'] ?? null;
$voucherType = $data['voucher_type'];
$originUrl = $data['origin_url'];

if (!preg_match('/^256\d{9}$/', $msisdn)) {
    error_log("Invalid MSISDN format in IOTEC initiate.php: $msisdn");
    echo json_encode(['error' => 'Invalid MSISDN format. Use 256XXXXXXXXX (e.g., 256771234567).']);
    exit;
}

$externalRef = 'IOTEC_' . time() . '_' . uniqid();

try {
    $pdo = getIotecDB();
    logIotec("Database connection established", 'INITIATE');
} catch (Exception $e) {
    logIotec("Database connection error: " . $e->getMessage(), 'INITIATE_ERROR');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO transactions (external_ref, msisdn, amount, status, origin_site, client_mac, email, voucher_type, origin_url)
        VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$externalRef, $msisdn, $amount, $originSite, $clientMac, $email, $voucherType, $originUrl]);
    logIotec("Transaction inserted into database", 'INITIATE', ['externalRef' => $externalRef]);
} catch (PDOException $e) {
    logIotec("Database insert error: " . $e->getMessage(), 'INITIATE_ERROR');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$collectionData = [
    'category' => 'MobileMoney',
    'currency' => 'UGX',
    'walletId' => IOTEC_WALLET_ID,
    'externalId' => $externalRef,
    'payer' => $msisdn,
    'payerName' => $originSite,
    'payerNote' => "WiFi voucher payment - $voucherType",
    'amount' => (int)$amount,
    'payeeNote' => "Site: $originSite, MAC: $clientMac, Type: $voucherType"
];

logIotec("Calling IOTEC collection API", 'INITIATE', ['collectionData' => $collectionData]);

$response = makeIotecApiRequest('POST', '/collections/collect', $collectionData);

if (isset($response['error'])) {
    $errorMessage = $response['error'];
    $errorDetails = isset($response['response']) ? json_encode($response['response']) : '';
    $httpCode = $response['httpCode'] ?? 'unknown';
    
    logIotec("IOTEC API returned error", 'INITIATE_ERROR', [
        'errorMessage' => $errorMessage,
        'httpCode' => $httpCode,
        'errorDetails' => $errorDetails
    ]);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE transactions 
            SET status = 'failed', status_message = ? 
            WHERE external_ref = ?
        ");
        $stmt->execute([$errorMessage . ' ' . $errorDetails, $externalRef]);
        error_log("IOTEC Transaction marked as FAILED in DB: $externalRef. Reason: $errorMessage");
    } catch (PDOException $e) {
        error_log("Database update error on failure in IOTEC initiate.php: " . $e->getMessage());
    }
    
    echo json_encode([
        'status' => -1,
        'errorMessage' => $errorMessage,
        'errorDetails' => $errorDetails,
        'httpCode' => $httpCode,
        'externalReference' => $externalRef,
        'debug' => [
            'fullResponse' => $response
        ]
    ]);
    exit;
}

$iotecTransactionId = $response['id'] ?? '';
$statusCode = $response['statusCode'] ?? '';
$statusMessage = $response['statusMessage'] ?? '';

logIotec("IOTEC API success - Transaction initiated", 'INITIATE_SUCCESS', [
    'iotecTransactionId' => $iotecTransactionId,
    'statusCode' => $statusCode,
    'statusMessage' => $statusMessage
]);

try {
    $stmt = $pdo->prepare("
        UPDATE transactions 
        SET transaction_ref = ?, status_message = ? 
        WHERE external_ref = ?
    ");
    $stmt->execute([$iotecTransactionId, $statusMessage, $externalRef]);
    logIotec("Transaction updated with IOTEC reference", 'INITIATE', ['iotecTransactionId' => $iotecTransactionId]);
} catch (PDOException $e) {
    logIotec("Database update error: " . $e->getMessage(), 'INITIATE_ERROR');
}

logIotec("=== PAYMENT INITIATION COMPLETED ===", 'INITIATE_SUCCESS', [
    'externalRef' => $externalRef,
    'iotecTransactionId' => $iotecTransactionId
]);

echo json_encode([
    'status' => 1,
    'transactionReference' => $iotecTransactionId,
    'externalReference' => $externalRef,
    'statusMessage' => $statusMessage,
    'debug' => [
        'iotecStatusCode' => $statusCode,
        'originSite' => $originSite,
        'note' => 'Check your phone to confirm the payment.'
    ]
]);
?>
