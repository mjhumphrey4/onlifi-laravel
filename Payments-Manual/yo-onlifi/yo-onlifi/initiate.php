<?php
// initiate.php

// Set timezone to East Africa Time (EAT) - UTC+3
date_default_timezone_set('Africa/Nairobi');

require_once 'config.php';
require './YoAPI.php'; // Path to the YoAPI class file

handleCorsPreflight();

// Set headers for JSON response
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 1 only for debugging

// Read input safely
try {
  $data = readRequestData();
} catch (InvalidArgumentException $e) {
  error_log("Request decode error in initiate.php: " . $e->getMessage());
  echo json_encode(['error' => $e->getMessage()]);
  exit;
}

// Add origin_site validation/checking
// Also add client_mac, email, voucher_type, origin_url
if (!isset($data['amount']) || !isset($data['msisdn']) || !isset($data['origin_site']) 
    || !isset($data['client_mac']) || !isset($data['voucher_type']) || !isset($data['origin_url'])) {
  error_log("Missing required fields in initiate.php: amount, msisdn, origin_site, client_mac, voucher_type, origin_url");
  echo json_encode(['error' => 'Missing required fields: amount, msisdn, origin_site, client_mac, voucher_type, origin_url']);
  exit;
}

$amount = (float) $data['amount'];
$msisdn = $data['msisdn'];
$originSite = trim($data['origin_site']); // Get the origin site ID from the frontend
$clientMac = $data['client_mac']; // Get client MAC
$email = $data['email'] ?? null; // Get email (optional)
$voucherType = $data['voucher_type']; // Get voucher type
$originUrl = $data['origin_url']; // Get origin URL

// Optional: Validate origin_site, client_mac, voucher_type, origin_url format if needed
// if (!preg_match('/^[a-zA-Z0-9_-]+$/', $originSite)) { ... }
// if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $clientMac)) { ... }
// if (!filter_var($email, FILTER_VALIDATE_EMAIL) && $email !== null) { ... }

// Validate MSISDN format (basic check for Uganda numbers)
if (!preg_match('/^256\d{9}$/', $msisdn)) {
    error_log("Invalid MSISDN format in initiate.php: $msisdn");
    echo json_encode(['error' => 'Invalid MSISDN format. Use 256XXXXXXXXX (e.g., 256771234567).']);
    exit;
}

// Generate a unique external reference for this transaction
$externalRef = 'TXN_' . time() . '_' . uniqid();

// Get database connection
try {
  $pdo = getDB();
} catch (Exception $e) {
  error_log("Database connection error in initiate.php: " . $e->getMessage());
  echo json_encode(['error' => 'Database connection failed']);
  exit;
}

// Insert initial transaction record into the OnLiFi tenant database.
try {
  insertTransaction($pdo, [
      'external_ref' => $externalRef,
      'msisdn' => $msisdn,
      'amount' => $amount,
      'status' => 'pending',
      'origin_site' => $originSite,
      'client_mac' => $clientMac,
      'email' => $email,
      'voucher_type' => $voucherType,
      'origin_url' => $originUrl,
      'site_id' => ONLIFI_SITE_ID,
      'created_at' => date('Y-m-d H:i:s'),
      'updated_at' => date('Y-m-d H:i:s'),
  ]);
  error_log("Transaction inserted into DB: $externalRef from origin site: $originSite, MAC: $clientMac, Type: $voucherType, URL: $originUrl");
} catch (Exception $e) {
  error_log("Database insert error in initiate.php: " . $e->getMessage());
  echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
  exit;
}

// --- Start YoAPI Specific Code ---
$yoAPI = new YoAPI(YOAPI_USERNAME, YOAPI_PASSWORD, YOAPI_MODE);

// Set the unique external reference generated above
$yoAPI->set_external_reference($externalRef);

// Set non-blocking for instant response
$yoAPI->set_nonblocking("TRUE");

// Define your IPN and Failure URLs using SITE_URL constant
$ipnUrl = SITE_URL . 'ipn.php'; // Adjust path as needed
$failureUrl = SITE_URL . 'failure.php'; // Adjust path as needed

// Set the notification URLs
$yoAPI->set_instant_notification_url($ipnUrl);
$yoAPI->set_failure_notification_url($failureUrl);

// Call the deposit function
$response = $yoAPI->ac_deposit_funds($msisdn, $amount, 'Feature Payment'); // Adjust narrative as needed

// --- End YoAPI Specific Code ---

// Check YoAPI response
if($response['Status'] == 'OK') {
    // Transaction initiated successfully
    $yoTransactionRef = $response['TransactionReference'] ?? '';
    $statusCode = $response['StatusCode'] ?? 0;
    $statusMessage = $response['StatusMessage'] ?? '';

    // Update the database with the Yo! transaction reference
    // The other fields (origin_site, client_mac, email, voucher_type, origin_url) were already set during initial insert
    try {
        updateTransaction($pdo, $externalRef, [
            'transaction_ref' => $yoTransactionRef,
            'status_message' => $statusMessage,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        error_log("Transaction updated in DB with Yo! ref: $yoTransactionRef from origin site: $originSite");
    } catch (Exception $e) {
        error_log("Database update error in initiate.php: " . $e->getMessage());
        // This is critical, but the transaction might still be pending, so we continue
    }

    // Determine status for frontend
    $frontendStatus = 1; // Assume pending for user action

    echo json_encode([
        'status' => $frontendStatus, // 1 for pending user action
        'transactionReference' => $yoTransactionRef, // Yo!'s transaction reference
        'externalReference' => $externalRef, // Your external reference
        'statusMessage' => $statusMessage,
        'debug' => [
            'yoApiStatusCode' => $statusCode,
            'originSite' => $originSite, // Optionally return origin_site to frontend for debugging
            'note' => 'Check your phone to confirm the payment.'
        ]
    ]);

} else {
    // YoAPI returned an error - Try IOTEC fallback immediately
    $errorMessage = $response['StatusMessage'] ?? 'Unknown error from Yo! Payments';
    $errorCode = $response['StatusCode'] ?? -1;

    // Update the database to reflect the failure
    try {
        updateTransaction($pdo, $externalRef, [
            'status' => 'failed',
            'status_message' => $errorMessage,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        error_log("YO initiate.php FAILED: $externalRef. Reason: $errorMessage. Attempting IOTEC fallback...");
    } catch (Exception $e) {
        error_log("Database update error on failure in initiate.php: " . $e->getMessage());
    }

    // Try IOTEC as fallback
    require_once 'payment_fallback_helper.php';
    require_once 'logger.php';
    
    // Retrieve the transaction for fallback
    $transaction = fetchTransactionBy($pdo, 'external_ref', $externalRef);
    
    if ($transaction) {
        $fallbackResult = retryPaymentWithIOTEC($transaction, $pdo, $logger);
        
        if ($fallbackResult['success']) {
            // Fallback initiated successfully - return success with IOTEC reference
            echo json_encode([
                'status' => 1, // Success - payment initiated via IOTEC
                'transactionReference' => $fallbackResult['iotec_transaction_id'],
                'externalReference' => $fallbackResult['iotec_external_ref'],
                'statusMessage' => 'Primary service failed. Payment initiated via backup service.',
                'fallbackUsed' => true,
                'debug' => [
                    'originalRef' => $externalRef,
                    'iotecRef' => $fallbackResult['iotec_external_ref'],
                    'note' => 'Check your phone to confirm the payment via backup service.'
                ]
            ]);
            exit;
        }
    }
    
    // If fallback also failed or transaction not found, return error
    echo json_encode([
        'status' => -1, // Indicate failure
        'errorCode' => $errorCode,
        'errorMessage' => $errorMessage,
        'externalReference' => $externalRef,
        'debug' => [
            'originSite' => $originSite,
            'note' => 'Both payment services failed'
        ]
    ]);
}
?>
